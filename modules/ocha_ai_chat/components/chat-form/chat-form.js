/* global once */
(function () {
  'use strict';

  // Some data needs to survive between executions of Drupal's `attach` method
  // so we instantiate it outside the Behavior itself.
  var oldScrollHeight;

  // Initialize. We do this outside Drupal.behaviors because it doesn't need to
  // run each time ajax gets called.
  window.parent.postMessage('ready', window.origin);

  Drupal.behaviors.ochaAiChatForm = {
    attach: function (context, settings) {
      once('ocha-ai-chat-form', '[data-drupal-selector="edit-chat"]', context).forEach(element => {
        var chatContainer = document.querySelector('[data-drupal-selector="edit-chat"] .fieldset-wrapper');
        var submitButton = document.querySelector('[data-drupal-selector="edit-submit"]');
        var chatHeight = this.padChatWindow();

        // Do some calculations to decide where to start our smooth scroll.
        if (oldScrollHeight) {
          var smoothScrollStart = oldScrollHeight - chatHeight;

          // Jump to where the bottom of the previous container was before the DOM
          // got updated. From there, we smooth-scroll to the bottom.
          chatContainer.scrollTo({top: smoothScrollStart, behavior: 'instant'});
          chatContainer.scrollTo({top: chatContainer.scrollHeight, behavior: 'smooth'});
        }
        else {
          chatContainer.scrollTo({top: chatContainer.scrollHeight, behavior: 'smooth'});
        }

        /**
         * Chat submission.
         *
         * Upate UI when submit is pressed. Each time ajax finishes, the whole
         * chat history will be re-inserted into the DOM. That means we can
         * temporarily inject whatever we like; it will get cleaned up for us.
         */
        function chatSend(ev) {
          // First check the question textarea for a value. We don't want to act
          // unless we have a value to send.
          var questionValue = document.querySelector('[data-drupal-selector="edit-question"]').value;

          // If we couldn't find a question, exit early.
          if (!questionValue) {
            ev.preventDefault();
            return;
          }

          // Build DOM nodes to be inserted.
          var chatContainer = document.querySelector('[data-drupal-selector="edit-chat"] .fieldset-wrapper');
          var chatResult = Drupal.behaviors.ochaAiChatUtils.createElement('div', {
            'class': 'ocha-ai-chat-result'
          }, {});
          var questionDl = Drupal.behaviors.ochaAiChatUtils.createElement('dl', {
            'class': 'chat'
          }, {});
          var questionWrapper = Drupal.behaviors.ochaAiChatUtils.createElement('div', {
            'class': 'chat__q chat__q--loading'
          }, {});
          var questionDt = Drupal.behaviors.ochaAiChatUtils.createElement('dt', {
            'class': 'visually-hidden'
          }, 'Question');
          var questionDd = Drupal.behaviors.ochaAiChatUtils.createElement('dd', {}, questionValue);

          // Prep all the DOM nodes for insertion.
          questionWrapper.append(questionDt);
          questionWrapper.append(questionDd);
          questionDl.append(questionWrapper);
          chatResult.append(questionDl);

          // Introduce a small delay before question gets inserted into DOM.
          setTimeout(() => {
            chatContainer.append(chatResult);

            // In this instance we use smooth scrolling. It won't be smooth
            // unless the continer can be scrolled to begin with, but if padding
            // was able to be added when the window opened, then it should work
            // from the very beginning of the chat history.
            chatContainer.scrollTo({top: chatContainer.scrollHeight, behavior: 'smooth'});

            // Store the scroll position so that we can attempt to smooth-scroll
            // when the form reloads with new data attached.
            oldScrollHeight = chatContainer.scrollHeight;

            // Remove old question from textarea.
            document.querySelector('[data-drupal-selector="edit-question"]').value = '';
          }, 200);
        }

        // Check all the input modes and add our client-side chat effects to the
        // form's main submit button.
        //
        // We use `mousedown` instead of `click` because the latter didn't seem
        // to have any effect when testing. It's possible that Drupal stops
        // event propagation, preventing a `click` from ever executing.
        //
        // Note: Drupal 10 has a bug that might seem like we introduced, but
        // its behavior comes from core. Ajax event listeners use mousedown so
        // forms will submit even when doing actions like right-click which
        // aren't meant to submit the form.
        //
        // @see https://www.drupal.org/project/drupal/issues/2616184
        submitButton.addEventListener('touchend', chatSend);
        submitButton.addEventListener('mousedown', chatSend);
        submitButton.addEventListener('keydown', function (ev) {
          // First check that the [Enter] key is being pressed.
          if (ev.keyCode === 13) {
            chatSend(ev);
          }
        });

        // Set up feedback observers.
        this.feedbackObservers();

        // Set up copy to clipboard buttons.
        this.copyToClipboard();

        // Initialize the button to toggle detailed feedback.
        this.toggleFeedback();

        // Listen for window resizes and recalculate the amount of padding needed
        // within the chat history.
        window.addEventListener('resize', Drupal.debounce(this.padChatWindow, 33));
      });
    },

    /**
     * Pad chat window
     *
     * Calculates the size of the chat window and adds padding to ensure there
     * is always a scrollable area. This allows the smooth-scroll code to create
     * the illusion of a chat UI like SMS or WhatsApp.
     *
     * @return int height of chat container minus some padding
     */
    padChatWindow: function (ev) {
      var chatContainerOuter = document.querySelector('[data-drupal-selector="edit-chat"]');
      var chatContainer = document.querySelector('[data-drupal-selector="edit-chat"] .fieldset-wrapper');

      // There's some bottom padding we have to subtract away.
      var chatHeight = chatContainerOuter.getBoundingClientRect().height - 16;
      chatContainer.style.setProperty('--oaic-padding-block-start', chatHeight + 'px');

      return chatHeight;
    },

    /**
     * Detailed feedback toggle
     *
     * When both modes are shown simultaneously, we hide the detailed feedback
     * until someone toggles it. The feedbackObservers function will handle the
     * scrolling, but this code marks an individual detailed feedback <details>
     * as non-hidden.
     */
    toggleFeedback: function () {
      const thumbs = document.querySelectorAll('.feedback-button--show-detailed');
      thumbs.forEach((el) => {
        el.addEventListener('click', (ev) => {
          // Find the <details> associated with this Answer.
          const targetFeedback = document.querySelector(`[data-drupal-selector="${ev.target.dataset.for}"]`);

          // Toggle visibility. The MutationObserver will scroll it into view.
          if (targetFeedback.hasAttribute('hidden')) {
            targetFeedback.removeAttribute('hidden');
            targetFeedback.setAttribute('open', '');
          }
          else {
            targetFeedback.setAttribute('hidden', '');
            targetFeedback.removeAttribute('open');
          }

          // Prevent Drupal from processing the form further.
          ev.preventDefault();
          ev.stopPropagation();
        });
      });
    },

    /**
     * Feedback observers
     *
     * We use two Mutation Observers to monitor when any feedback section is
     * either toggled or submitted. We react to the two events differently.
     * Everything that happens here is totally optional is considered a UX
     * improvement instead of core functionality.
     */
    feedbackObservers: function () {
      const targetElements = document.querySelectorAll('.ocha-ai-chat-result-feedback');

      // Options for the observer (which mutations to observe)
      const config = {attributes: true, childList: true};

      // Callback function to execute when mutations are observed
      const callback = (mutationList, observer) => {
        for (const mutation of mutationList) {
          // When a <details> feedback element gets expanded.
          if (mutation.type === 'attributes') {
            setTimeout(() => {
              // If the feedback was opened, scroll to its bottom edge.
              if (mutation.target.hasAttribute('open') && !mutation.target.hasAttribute('hidden')) {
                mutation.target.scrollIntoView({block: 'end', behavior: 'smooth'});
              }
            }, 250);
          }

          // When feedback was submitted and a message appeared.
          if (mutation.type === 'childList') {
            setTimeout(() => {
              // Scroll to the feedback confirmation message.
              mutation.target.scrollIntoView({block: 'end', behavior: 'smooth'});
            }, 150);
          }
        }
      };

      // Create an observer instance linked to the callback function
      const observer = new MutationObserver(callback);

      // Start observing the targets for configured mutations.
      targetElements.forEach((el) => {
        observer.observe(el, config);
      });
    },

    /**
     * Copy to Clipboard
     *
     * Needs to be run every time the form reloads. It will find all the copy
     * buttons and attach an event listener that copies individual answers to
     * the user's clipboard.
     *
     * Adapted from CD Social Links in CD v9.4.0
     *
     * @see https://github.com/UN-OCHA/common_design/blob/v9.4.0/libraries/cd-social-links/cd-social-links.js
     */
    copyToClipboard: function () {
      // Collect all "copy" URL buttons.
      const copyButtons = document.querySelectorAll('.feedback-button--copy');

      // Process links so they copy URL to clipboard.
      copyButtons.forEach(function (el) {
        // First, define the status element for each button.
        var status = el.parentNode.querySelector('[role=status]');

        // Add our event listener so people can copy to clipboard.
        el.addEventListener('click', function (ev) {
          var tempInput = document.createElement('input');
          var textToCopy = document.querySelector('#' + el.dataset.for).innerHTML.replaceAll('<br>', '\n');

          try {
            if (navigator.clipboard) {
              // Easy way possible?
              navigator.clipboard.writeText(textToCopy);
            }
            else {
              // Legacy method
              document.body.appendChild(tempInput);
              tempInput.value = textToCopy;
              tempInput.select();
              document.execCommand('copy');
              document.body.removeChild(tempInput);
            }

            // If we got this far, don't let the link click through.
            ev.preventDefault();
            ev.stopPropagation();

            // Show user feedback and remove after some time.
            status.removeAttribute('hidden');
            status.innerText = el.dataset.message;

            // Hide message.
            setTimeout(function () {
              status.setAttribute('hidden', '');
            }, 2500);
            // After message is hidden, remove status contents.
            setTimeout(function () {
              status.innerText = '';
            }, 3000);
          }
          catch (err) {
            // Log errors to console.
            console.error(err);
          }
        });
      });
    },
  };
})();
