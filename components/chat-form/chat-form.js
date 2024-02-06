(function () {
  'use strict';

  // Initialize. We do this outside Drupal.behaviors because it doesn't need to
  // run each time ajax gets called.
  window.parent.postMessage('ready', window.origin);

  Drupal.behaviors.ochaAiChatForm = {
    attach: function (context, settings) {
      var chatContainer = document.querySelector('[data-drupal-selector="edit-chat"] .fieldset-wrapper');

      // Scroll to bottom of chat container. We can't use smooth scrolling to
      // create a convincing effect without storing and calculating the position
      // from before the ajax submission, so we immediately set it to the bottom
      // so that any new content is prominently visible neat the text input.
      chatContainer.scrollTop = chatContainer.scrollHeight;

      // Custom actions when submit is pressed. Each time ajax finishes, the whole
      // chat history will be re-rendered so we can temporarily inject whatever we
      // like and it will get cleaned up.
      var submitButton = document.querySelector('[data-drupal-selector="edit-submit"]');
      // TODO: will this work on mobile or do we need to attach the listener to
      // separate events? e.g. touchend
      submitButton.addEventListener('mousedown', event => {
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
          }, 'Question'
        );
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
        }, 200);
      });
    },
  };

})();
