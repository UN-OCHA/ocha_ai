(function () {
  'use strict';

  Drupal.behaviors.ochaAiChatUtils = {
    /**
     * Create a DOM element with the given attributes and content.
     *
     * @param {String} tag
     *   The element tag.
     * @param {Object} attributes
     *   Map of attribute names and values.
     * @param {String|Element|Array} content
     *   Either a text, an DOM element or an array of DOM elements.
     *
     * @return Element
     *   The created DOM element.
     */
    createElement: function(tag, attributes, content) {
      const element = document.createElement(tag);
      if (typeof attributes === 'object') {
        for (const attribute in attributes) {
          if (attributes.hasOwnProperty(attribute)) {
            let value = attributes[attribute];
            element.setAttribute(attribute, Array.isArray(value) ? value.join(' ') : value);
          }
        }
      }
      switch (typeof content) {
        case 'string':
          element.appendChild(document.createTextNode(content));
          break;

        case 'object':
          // Assume it's is DOM element.
          if (content.nodeType) {
            element.appendChild(content);
          }
          // Assume it's a list of DOM elements.
          else if (typeof content.length !== 'undefined') {
            for (var i = 0, l = content.length; i < l; i++) {
              element.appendChild(content[i]);
            }
          }
          break;
      }
      return element;
    }
  };

})();
