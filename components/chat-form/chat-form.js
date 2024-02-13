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

      // Upate UI when submit is pressed. Each time ajax finishes, the whole
      // chat history will be re-inserted into the DOM. That means we can
      // temporarily inject whatever we like and it will get cleaned up for us.
      function chatSend (ev) {
        // First check the question textarea for a value. We don't want to act
        // unless we have a value to send.
        var questionValue = document.querySelector('[data-drupal-selector="edit-question"]').value;

        // If we couldn't find a question, exit early.
        if (!questionValue) {
          ev.preventDefault();
          return;
        }

        var chatContainer = document.querySelector('[data-drupal-selector="edit-chat"] .fieldset-wrapper');
        var chatResult = Drupal.behaviors.ochaAiChatUtils.createElement('div', {
          'class': 'ocha-ai-chat-result',
        }, {});
        var questionDl = Drupal.behaviors.ochaAiChatUtils.createElement('dl', {
          'class': 'chat',
        }, {});
        var questionWrapper = Drupal.behaviors.ochaAiChatUtils.createElement('div', {
          'class': 'chat__q chat__q--loading',
        }, {});
        var questionDt = Drupal.behaviors.ochaAiChatUtils.createElement('dt', {
          'class': 'visually-hidden',
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

          // In this instance we use smooth scrolling. It won't be smooth unless
          // the continer can be scrolled to begin with, but if padding was able
          // to be added when the window opened, then it should work from the
          // very beginning of the chat history.
          chatContainer.scrollTo({top: chatContainer.scrollHeight, behavior: 'smooth'});

          // Store the scroll position so that we can attempt to smooth-scroll
          // when the form reloads with new data attached.
          oldScrollHeight = chatContainer.scrollHeight;
        }, 200);
      };

      // Check all the input modes and add our client-side effect.
      //
      // We use `mousedown` instead of `click` because the latter didn't seem to
      // have any effect when testing. It's possible that Drupal stops event
      // propagation, preventing a `click` from ever executing.
      //
      // Note: Drupal 10 has a bug that might seem like we introduced, but
      // its behavior comes from core. Ajax event listeners use mousedown so
      // forms will submit even when doing actions like right-click which aren't
      // meant to submit the form.
      //
      // @see https://www.drupal.org/project/drupal/issues/2616184
      submitButton.addEventListener('touchend', chatSend);
      submitButton.addEventListener('mousedown', chatSend);
      submitButton.addEventListener('keydown', function(ev) {
        // First check that the [Enter] key is being pressed.
        if (ev.keyCode === 13) {
          chatSend(ev);
        }
      });

      // Listen for window resizes and recalculate the amount of padding needed
      // within the chat history.
      window.addEventListener('resize', Drupal.debounce(this.padChatWindow, 33));
    },

    // Calculates the size of the chat window and adds padding to ensure there
    // is always a scrollable area. This allows the smooth-scroll code to create
    // the illusion of a chat UI like SMS or WhatsApp.
    padChatWindow: function (ev) {
      var chatContainerOuter = document.querySelector('[data-drupal-selector="edit-chat"]');
      var chatContainer = document.querySelector('[data-drupal-selector="edit-chat"] .fieldset-wrapper');

      // There's some bottom padding we have to subtract away.
      var chatHeight = chatContainerOuter.getBoundingClientRect().height - 16;
      chatContainer.style.setProperty('--oaic-padding-block-start', chatHeight + 'px');

      return chatHeight;
    },
  };

})();
