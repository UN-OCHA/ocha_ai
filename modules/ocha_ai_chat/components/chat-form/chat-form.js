/* global once */
(function () {
  'use strict';

  // Initialize. We do this outside Drupal.behaviors because it doesn't need to
  // run each time ajax gets called.
  window.parent.postMessage('ready', window.origin);

  Drupal.behaviors.ochaAiChatForm = {
    attach: function (context, settings) {
      const createElement = Drupal.behaviors.ochaAiChatUtils.createElement;
      const chatContainerSelector = '[data-drupal-selector="edit-chat"] > .fieldset-wrapper';

      // Observe elements added to the form wrapper's parent to smooth scroll
      // when the chat container is updated (ex: new question or answer).
      once('ocha-ai-chat-form', '.ocha-ai-chat-chat-form-wrapper', context).forEach(element => {
        const parent = element.parentNode;

        // Check if the chat container is a child of an element in the given
        // node list.
        const hasChatContainer = (nodeList) => {
          for (let node of nodeList) {
            if (node.querySelector(chatContainerSelector)) {
              return true;
            }
          }
        };

        // Check if the given node is the chat container.
        const isChatContainer = (node) => {
          return node.classList.contains('fieldset-wrapper') &&
            node.parentNode.getAttribute('data-drupal-selector') === 'edit-chat';
        };

        // Scroll "smoohtly" to the bottom of the chat container when content
        // is added for example.
        const scrollChatContainer = (scrollToPrevious) => {
          const chatContainer = document.querySelector(chatContainerSelector);

          // There is some blank padding initially in the chat container to
          // allow the smooth scrolling effect.
          //
          // If there is content, adjust the height of the `:before` pseudo
          // element so that there is less scrollable blank area when the chat
          // container is populated with real content.
          if (chatContainer.firstElementChild) {
            const sh = chatContainer.scrollHeight;
            const ch = chatContainer.clientHeight;
            const ot = chatContainer.firstElementChild.offsetTop;
            // The initial height of the pseudo element is 200% (equivalent of
            // 2 * client height.
            const cp = Math.max((2 * ch) - (sh - ot), 0);

            chatContainer.style.setProperty('--oaic-chat-container-padding', cp + 'px');
          }

          // Scroll to the previous position directly so the smooth scrolling
          // doesn't start from the top of the chat container.
          if (scrollToPrevious) {
            const top = chatContainer.lastElementChild.offsetTop;
            chatContainer.scrollTo({top: top, behavior: 'instant'});

          }

          // Delay a bit the smooth scrolling to give a less instant effect.
          setTimeout(() => {
            chatContainer.scrollTo({top: chatContainer.scrollHeight, behavior: 'smooth'});
          }, 100);
        };

        // Mutation observer callback to determine if we should scroll to the
        // bottom of the chat container.
        const scrollObserverCallback = (mutationList, observer) => {
          let scroll = false;
          let scrollToPrevious = false;

          for (const mutation of mutationList) {
            if (!scroll && mutation.addedNodes.length > 0) {
              // This is triggered when the question is added to the existing
              // chat container.
              if (isChatContainer(mutation.target)) {
                scroll = true;
                scrollToPrevious = false;
              }
              // This is triggered when the chat is recreated in which case
              // the new chat container was added.
              else if (mutation.target === parent && hasChatContainer(mutation.addedNodes)) {
                scroll = true;
                scrollToPrevious = true;
              }
            }
          }

          if (scroll) {
            scrollChatContainer(scrollToPrevious);
          }
        };

        const scrollObserver = new MutationObserver(scrollObserverCallback);
        scrollObserver.observe(parent, {childList: true, subtree: true});

        // Scroll to the bottom of the container initially. This allows to
        // reveal the chat instructions smoothly as if given by the bot.
        scrollChatContainer(false);
      });

      // Handle chat interaction: sending, copying, rating.
      once('ocha-ai-chat-form', '[data-drupal-selector="edit-chat"]', context).forEach(element => {
        var submitButton = document.querySelector('[data-drupal-selector="edit-submit"]');
        var questionTextarea = document.querySelector('[data-drupal-selector="edit-question"]');

        /**
         * Chat submission.
         *
         * Upate UI when submit is pressed. Each time ajax finishes, the whole
         * chat history will be re-inserted into the DOM. That means we can
         * temporarily inject whatever we like; it will get cleaned up for us.
         */
        function chatSend(ev) {
          // Ignore right clicks.
          if (ev.button && ev.button == 2) {
            ev.preventDefault();
            return;
          }

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
          var chatResult = createElement('div', {
            'class': 'ocha-ai-chat-result'
          }, {});
          var questionDl = createElement('dl', {
            'class': 'chat'
          }, {});
          var questionWrapper = createElement('div', {
            'class': 'chat__q chat__q--loading'
          }, {});
          var questionDt = createElement('dt', {
            'class': 'visually-hidden'
          }, 'Question');
          var questionDd = createElement('dd', {}, questionValue);

          // Prep all the DOM nodes for insertion.
          questionWrapper.append(questionDt);
          questionWrapper.append(questionDd);
          questionDl.append(questionWrapper);
          chatResult.append(questionDl);

          // Introduce a small delay before question gets inserted into DOM.
          setTimeout(() => {
            chatContainer.append(chatResult);

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

        // Initialize the event so that pressing enter in the input textarea
        // submits the form.
        questionTextarea.addEventListener('keydown', function (event) {
          if (event.keyCode == 13 && !event.shiftKey) {
            event.preventDefault();
            // We don't call chatSend directly because this will not trigger
            // the ajax event attached to the submit button.
            submitButton.dispatchEvent(new Event('mousedown'));
          }
        });

        // Set up feedback observers.
        this.feedbackObservers();

        // Set up copy to clipboard buttons.
        this.copyToClipboard();

        // Initialize the button to toggle detailed feedback.
        this.toggleFeedback();
      });
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

        // Event listener so people can copy to clipboard.
        //
        // As of hook_update_10005() the button is hooked up to the Drupal form
        // so that it can submit and record that the copy button was pressed.
        // Drupal handles displaying success feedback to the user. This code is
        // still showing feedback in case of failure to copy.
        el.addEventListener('mousedown', function (ev) {
          var tempInput = document.createElement('input');
          var textToCopy = document.querySelector('#' + el.dataset.for).innerHTML.replaceAll('<br>', '\n');
          var status = el.parentNode.querySelector('[role=status]');

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
          }
          catch (err) {
            // Log errors to console.
            console.error(err);

            // Show user feedback and remove after some time.
            status.removeAttribute('hidden');
            status.innerText = status.dataset.failure;

            // Hide message.
            setTimeout(function () {
              status.setAttribute('hidden', '');
            }, 2500);
            // After message is hidden, remove status contents.
            setTimeout(function () {
              status.innerText = '';
            }, 3000);

            // Since the copy wasn't successful, we prevent Drupal from logging.
            ev.stopImmediatePropagation();
          }
        });
      });
    }
  };
})();
