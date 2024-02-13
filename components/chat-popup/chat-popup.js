(function () {
  'use strict';

  function processPopup(popup) {
    // Mark the popup as processed so the following code runs only once.
    popup.setAttribute('data-ocha-ai-chat-chat-popup-processed', '');
    popup.setAttribute('aria-modal', true);
    popup.setAttribute('aria-label', Drupal.t('Ask ReliefWeb'));
    popup.setAttribute('role', 'dialog');
    popup.setAttribute('hidden', '');

    const link = popup.querySelector('a');

    const iframe = Drupal.behaviors.ochaAiChatUtils.createElement('iframe', {
      'class': 'ocha-ai-chat-chat-popup__iframe',
      'data-src': link.href
    });

    // Listen to message events from the iframe.
    window.addEventListener('message', function (event) {
      if (event.source === iframe.contentWindow && event.data === 'ready') {
        popup.classList.remove('ocha-ai-chat-chat-popup-loading');
      }
    });

    // Replace the link with the iframe.
    link.replaceWith(iframe);

    // Replace the link with a button to open the chat window.
    const openButton = Drupal.behaviors.ochaAiChatUtils.createElement('button', {
      'type': 'button',
      'class': 'data-ocha-ai-chat-chat-popup__button-open'
    }, Drupal.behaviors.ochaAiChatUtils.createElement('span', {
      'class': 'data-ocha-ai-chat-chat-popup__button-open__label visually-hidden'
    }, Drupal.t('Open chat')));

    popup.before(openButton);

    // Clicking/tapping will show the chat popup.
    openButton.addEventListener('click', event => {
      if (!iframe.src) {
        popup.classList.add('ocha-ai-chat-chat-popup-loading');
        iframe.src = iframe.getAttribute('data-src');
      }

      popup.removeAttribute('hidden');
      document.documentElement.classList.add('is--mobile-scroll-locked');
    });

    // Create a separate close button that sits inside the <section> and can
    // be more easily positioned in all viewport sizes.
    const closeButton = Drupal.behaviors.ochaAiChatUtils.createElement('button', {
      'type': 'button',
      'class': 'data-ocha-ai-chat-chat-popup__button-close'
    }, Drupal.behaviors.ochaAiChatUtils.createElement('span', {
      'class': 'data-ocha-ai-chat-chat-popup__button-close__label visually-hidden'
    }, Drupal.t('Close chat')));

    // Clicking/tapping will hide the chat popup.
    closeButton.addEventListener('click', event => {
      popup.setAttribute('hidden', '');
      document.documentElement.classList.remove('is--mobile-scroll-locked');
    });

    popup.prepend(closeButton);
  }

  /**
   * Drupal behavior for the chat popup.
   */
  Drupal.behaviors.ochaAiChatPopup = {
    attach: function (context, settings) {
      // Get the "Ask ReliefWeb" popup.
      const popup = context.querySelector('[data-ocha-ai-chat-chat-popup]');

      // Convert to a popup dialog.
      if (popup && !popup.hasAttribute('data-ocha-ai-chat-chat-popup-processed')) {
        processPopup(popup);
      }
    }
  };

})(Drupal);
