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

      // Add padding equal to chat window so we can always scroll. We take 16px
      // away to avoid overages due to padding-bottom.
      var chatHeight = chatContainer.getBoundingClientRect().height - 16;
      chatContainer.style.paddingBlockStart = chatHeight + 'px';

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

      // Upate UI when submit is pressed. Each time ajax finishes, the
      // whole chat history will be re-inserted into the DOM. That means we can
      // temporarily inject whatever we like and it will get cleaned up for us.
      function chatSend (event) {
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
        var questionValue = document.querySelector('[data-drupal-selector="edit-question"]').value;
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
          // the continer can be scrolled to begin with, so in practice the
          // first one never has a smooth scroll.
          chatContainer.scrollTo({top: chatContainer.scrollHeight, behavior: 'smooth'});

          // Store the scroll position so that we can attempt to smooth-scroll
          // when the form reloads with new data attached.
          oldScrollHeight = chatContainer.scrollHeight;
        }, 200);
      };

      // All the input modes!
      submitButton.addEventListener('touchend', chatSend);
      submitButton.addEventListener('mousedown', chatSend);
      submitButton.addEventListener('keydown', function(ev) {
        // First check that the [Enter] key is being pressed.
        if (ev.keyCode === 13) {
          chatSend(ev);
        }
      });
    },
  };

})();
