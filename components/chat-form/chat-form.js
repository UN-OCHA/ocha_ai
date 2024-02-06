(function () {
  'use strict';

  // Initialize. We do this outside Drupal.behaviors because it doesn't need to
  // run each time ajax gets called.
  window.parent.postMessage('ready', window.origin);

  Drupal.behaviors.ochaAiChatForm = {
    attach: function (context, settings) {
      // Scroll to bottom of chat contents container.
      var chatContainer = document.querySelector('[data-drupal-selector="edit-chat"] .fieldset-wrapper');
      chatContainer.scrollTop = chatContainer.scrollHeight;
    },
  };
})();
