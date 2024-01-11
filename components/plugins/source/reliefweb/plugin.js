/* global Drupal, once, SimpleAutocomplete, SimpleDatePicker */
/**
 * Script to handle the advanced search filters for the rivers.
 *
 * @todo with the drop of support for IE, we can simplify this script.
 * @todo review the ids.
 */
(function (Drupal) {
  'use strict';

  // @todo find a better name.
  class Handler {

    /**
     * Create the plugin handler.
     *
     * @param {Element} element
     *   The form element to transform.
     * @param {object} settings.
     *   baseClass, filters, labels, placeholder, announcements and heading
     *   level.
     */
    constructor(element, settings = {}) {
      // Widget container element.
      this.element = element;

      // Settings.
      this.language = settings.language;
      this.baseId = settings.baseId;
      this.baseClass = settings.baseClass;
      this.baseUrl = settings.baseUrl;
      this.riverUrl = settings.riverUrl;
      this.apiUrl = settings.apiUrl;
      this.apiPayload = settings.apiPayload;
      this.openWrapper = settings.openWrapper;

      this.searchHelp = settings.searchHelp || '';
      this.headingLevel = settings.headingLevel || 1;
      this.labels = settings.labels || {};
      this.placeholders = settings.placeholders || {};
      this.announcements = settings.announcements || {};
      this.views = settings.views || [];
      this.filters = settings.filters || [];
      this.operators = settings.operstors || [];

      this.defaultOperators = {};
      this.filterMap = {};
      this.viewMap = {};

      this.filters.forEach(filter => {
        // Map the filters to their default operator.
        this.defaultOperators[filter.code] = (filter.operator || 'or').toLowerCase();
        // Map the filters to their code.
        this.filterMap[filter.code] = filter;
      });

      this.views.forEach(view => {
        // Map the views to their id.
        this.viewMap[view.id] = view;
      });

      this.operatorMap = {
        '(': 'with',
        '!(': 'without',
        ')_(': 'and-with',
        ')_!(': 'and-without',
        ').(': 'or-with',
        ').!(': 'or-without',
        '.': 'or',
        '_': 'and'
      };

      // DOM elements.
      this.filterContainer = null;
      this.filterContent = null;
      this.filterSelection = null;
      this.filterList = null;

      this.searchInput = null;
      this.searchButton = null;

      this.activeToggler = null;
      this.dialog = null;
      this.dialogAdd = null;
      this.dialogCancel = null;
      this.clear = null;
      this.apply = null;
      this.parameter = null;
      this.operatorSelector = null;
      this.fieldSelector = null;

      // Original filter selection.
      this.originaSelection = '';
      this.originaSearch = '';
      this.originaView = '';

      // List of widgets.
      this.widgets = [];

      // Keep track of the max ID to ensure uniqueness.
      this.maxId = 0;

      // Track the current mode.
      this.advancedMode = false;

      // Parameters with the currently selected filters, search and view.
      this.parameters = new URLSearchParams();

      // Number formatter.
      this.numberFormatter = new Intl.NumberFormat(this.language);

      // Key codes.
      this.keyCodes = {
        TAB: 9,
        ENTER: 13,
        ESC: 27,
        END: 35,
        HOME: 36,
        LEFT: 37,
        UP: 38,
        RIGHT: 39,
        DOWN: 40
      };
    }

    /**
     * Initialize the handler.
     */
    initialize() {
      // Retrieve the initial search parameters.
      this.parseInitialRiverUrl();

      // Create the widget container.
      this.createWrapper();

      // Create the autocomplete and datepicker widgets.
      this.createWidgets();

      // Create the operator switchers.
      this.createOperatorSwitchers();

      // Enable the first widget.
      this.switchWidget();

      // Update the search box and populate the river.
      // @todo update the selected filter based on the initial advanced search.
      this.parseAdvancedSearch();
      this.updateSearch();
      this.updateRiver();

      // Update the filter, view, search and river when the river URL is changed
      // manually.
      this.element.addEventListener('blur', event => {
        this.parseRiverUrl();
        this.parseAdvancedSearch();
        this.updateSearch();
        this.updateRiver();
      });
    }

    /**
     * Retrieve the initial filter, search and view from the widget element.
     */
    parseInitialRiverUrl() {
      const parameters = new URL(this.riverUrl).searchParams;

      // Store the initial values. This is mostly used to compare changes
      // to highlight the apply filters button for example.
      this.initialAdvancedSearch = (parameters.get('advanced-search') || '').trim();
      this.initialSearch = (parameters.get('search') || '').trim();
      this.initialView = (parameters.get('view') || '').trim();

      // Set the current parameters.
      if (this.initialAdvancedSearch) {
        this.parameters.set('advanced-search', this.initialAdvancedSearch);
      }
      if (this.initialSearch) {
        this.parameters.set('search', this.initialSearch);
      }
      if (this.initialView) {
        this.parameters.set('view', this.initialView);
      }
    }

    /**
     * Update the parameters from the current river URL value.
     */
    parseRiverUrl() {
      const url = this.element.value.trim();
      const parameters = url ? new URL(url).searchParams : new URLSearchParams();

      for (const key of ['advanced-search', 'search', 'view']) {
        const value = (parameters.get(key) || '').trim();
        if (value) {
          this.parameters.set(key, value);
        }
        else {
          this.parameters.delete(key);
        }
      }

      // @todo remove if/when we allow the selection of the view.
      if (this.initialView) {
        this.parameters.set('view', this.initialView);
      }
    }

    /**
     * Update the river URL with the selected parameters.
     */
    updateRiverUrl() {
      // Get the search query.
      const search = this.searchInput.value.trim();
      if (search) {
        this.parameters.set('search', search);
      }
      else {
        this.parameters.delete('search');
      }

      // @todo retrieve that from the view widget if any.
      const view = this.initialView;
      if (view) {
        this.parameters.set('view', view);
      }
      else {
        this.parameters.delete('view');
      }

      const url = new URL(this.riverUrl);

      for (const key of ['advanced-search', 'search', 'view']) {
        if (this.parameters.has(key)) {
          url.searchParams.set(key, this.parameters.get(key));
        }
        else {
          url.searchParams.delete(key);
        }
      }

      this.element.value = url.toString();
    }

    /**
     * Convert the river URL to a API payload.
     *
     * @return {Object}
     *   ReliefWeb API payload.
     */
    convertRiverUrl() {
      const value = this.element.value.trim();
      if (value) {
        const url = new URL(value);
        const parameters = new URLSearchParams(url.search);
        const advancedSearch = (parameters.get('advanced-search') || '').trim();
        const search = (parameters.get('search') || '').trim();
        const view = (parameters.get('view') || '').trim();

        // Retrieve the view filter if any.
        let viewFilter = null;
        if (view && this.viewMap[view] && this.viewMap[view].filter) {
          viewFilter = this.viewMap[view].filter;
        }

        // Retrieve the advanced search filter if any.
        let advancedSearchFilter = null;
        if (advancedSearch) {
          advancedSearchFilter = this.convertAdvancedSearch(advancedSearch);
        }

        // Combined the filters.
        let filter = null;
        if (viewFilter && advancedSearchFilter) {
          filter = {
            conditions: [
              viewFilter,
              advancedSearchFilter
            ],
            operator: 'AND'
          };
        }
        else {
          filter = viewFilter || advancedSearchFilter;
        }

        const payload = this.apiPayload;

        if (search) {
          payload.query = {value: search};
        }
        if (filter) {
          if (payload.filter) {
            if (filter.conditions && filter.operator === 'AND') {
              filter.conditions.push(payload.filter);
            }
            else {
              filter = {
                conditions: [
                  filter,
                  payload.filter
                ],
                operator: 'AND'
              };
            }
          }
          payload.filter = filter;
        }

        return payload;
      }

      return null;
    }

    /**
     * Convert an advanced search parameter into an API filter.
     *
     * @param {String} parameter
     *   Advanced search parameter value.
     *
     * @return {Object|null}
     *   The API filter.
     */
    convertAdvancedSearch(parameter) {
      if (!parameter) {
        return null;
      }

      // Validate.
      const validationPattern = /^(((^|[._])!?)\(([A-Z]+(-?\d+|\d+-\d*|[0-9a-z-]+)([._](?!\)))?)+\))+$/;
      if (!validationPattern.test(parameter)) {
        return null;
      }

      let root = {
        conditions: [],
        operator: 'AND'
      };
      let filter = null;

      // Parse the parameter.
      const parsingPattern = /(!?\(|\)[._]!?\(|[._])([A-Z]+)(\d+-\d*|-?\d+|[0-9a-z-]+)/g;
      const matches = parameter.matchAll(parsingPattern);

      for (const match of matches) {
        const code = match[2];

        const definition = this.filterMap[code];
        // @todo we may need to fix the operator in that case.
        if (!definition) {
          continue;
        }

        const type = definition.type;
        const field = definition.field;

        let operator = this.operatorMap[match[1]];
        let value = match[3];

        if (operator.includes('with')) {
          const newFilter = {
            conditions: [],
            operator: 'AND'
          };

          if (operator.includes('out')) {
            newFilter.negate = true;
          }

          operator = operator.includes('or') ? 'OR' : 'AND';
          if (operator !== root.operator) {
            root = {
              conditions: [root],
              operator: operator
            };
          }
          root.conditions.push(newFilter);
          filter = root.conditions[root.conditions.length - 1];
        }

        if (filter) {
          // @todo Validate fixed values for example?
          if (type === 'date') {
            let [from, to] = value.split('-').map(date => {
              return date !== '' ? this.createDate(date) : null;
            });
            if (typeof to === 'undefined') {
              to = from.clone();
            }
            value = {};
            if (from) {
              value.from = from.format('YYYY-MM-DDT00:00:00+00:00');
            }
            if (to) {
              value.to = to.add('day', 1).substract('second', 1).format('YYY-MM-DDT00:00:00+00:00');
            }
          }
          filter.operator = operator;
          filter.conditions.push({
            field: field,
            value: value
          });
        }
      }

      if (root.conditions.length === 0) {
        return null;
      }
      else if (root.conditions.length === 1) {
        return root.conditions[0];
      }
      return root;
    }

    /**
     * Update the search.
     */
    updateSearch() {
      this.searchInput.value = (this.parameters.get('search') || '').trim();
    }

    /**
     * Update the river with the new river URL.
     *
     * @todo skip if the url didn't change.
     */
    updateRiver() {
      this.toggleLoading(true);
      const payload = this.convertRiverUrl();
      if (!payload) {
        this.displayNoResults();
      }
      else {
        fetch(this.apiUrl, {
          method: 'POST',
          body: JSON.stringify(payload)
        })
        .then(response => {
          if (response.ok) {
            return response.json();
          }
          else {
            throw new Error('Failure');
          }
        })
        .then(json => {
          this.updateRiverResults(json);
          this.populateRiverList(json);
          this.toggleLoading(false);
          this.updateFilterSelection(false);
        })
        .catch(error => {
          this.displayNoResults();
        });
      }
    }

    /**
     * Toggle the loading overlay.
     *
     * @param {Boolean} show
     *   If true, show the overlay.
     */
    toggleLoading(show = true) {
      this.riverContainer.classList.toggle(this.baseClass + '-loading', show);
    }

    /**
     * Populate the river list from the API data.
     *
     * @param {Object} data
     *   Data from the ReliefWeb API.
     */
    populateRiverList(data) {
      if (!data || !Array.isArray(data.data) || data.data.length === 0) {
        this.displayNoResults();
      }
      else {
        const articleBaseClass = this.baseClass + '__river__article';

        const articles = data.data.map(item => {
          const fields = item.fields;

          // Header.
          const countrySlug = this.createElement('p', {
            'class': articleBaseClass + '__country-slug'
          }, [
            this.createElement('a', {
              'class': articleBaseClass + '__country-slug__link',
              'href': this.baseUrl + '/country/' + fields.primary_country.iso3.toLowerCase(),
              'target': '_blank',
              'rel': 'noreferrer noopener'
            }, fields.primary_country.shortname || fields.primary_country.name)
          ]);

          if (fields.country.length > 0) {
            countrySlug.appendChild(this.createElement('span', {
              'class': articleBaseClass + '__country-slug__more'
            }, this.labels.article.more.replace('_count_', ' + ' + fields.country.length)));
          }

          const title = this.createElement('h' + (this.headingLevel + 2), {
            'class': articleBaseClass + '__title'
          }, this.createElement('a', {
            'href': fields.url_alias,
            'target': '_blank',
            'rel': 'noopener noreferrer'
          }, fields.title));

          const header = this.createElement('header', {
            'class': articleBaseClass + '__header'
          }, [
            countrySlug,
            title
          ]);

          // Footer.
          const format = Array.isArray(fields.format) ? fields.format[0] : fields.format;

          const sources = fields.source.slice(0, 3).map(source => {
            return this.createElement('li', {
              'class': articleBaseClass + '__meta__tag-value__list__item'
            }, source.shortname || source.name);
          });
          if (fields.source.length > 3) {
            sources.push(this.createElement('li', {
              'class': [
                articleBaseClass + '__meta__tag-value__list__item',
                articleBaseClass + '__meta__tag-value__list__item--more'
              ]
            }, this.labels.article.more.replace('_count_', ' + ' + (fields.source.length - 3))));
          }
          sources[sources.length - 1].classList.add(articleBaseClass + '__meta__tag-value__list__item--last');

          const meta = this.createElement('dl', {
            'class': [
              articleBaseClass + '__meta',
              articleBaseClass + '__meta--core'
            ]
          }, [
            // Format.
            this.createElement('dt', {
              'class': [
                articleBaseClass + '__meta__tag-label',
                articleBaseClass + '__meta__tag-label--format'
              ]
            }, this.labels.article.format),
            this.createElement('dd', {
              'class': [
                articleBaseClass + '__meta__tag-value',
                articleBaseClass + '__meta__tag-value--format'
              ],
              'data-format': format.id
            }, format.name),
            // Source.
            this.createElement('dt', {
              'class': [
                articleBaseClass + '__meta__tag-label',
                articleBaseClass + '__meta__tag-label--source'
              ]
            }, this.labels.article[fields.source.length > 1 ? 'sources' : 'source']),
            this.createElement('dd', {
              'class': [
                articleBaseClass + '__meta__tag-value',
                articleBaseClass + '__meta__tag-value--source'
              ]
            }, this.createElement('ul', {
              'class': articleBaseClass + '__meta__tag-value__list'
            }, sources)),
            // Publication date.
            this.createElement('dt', {
              'class': [
                articleBaseClass + '__meta__tag-label',
                articleBaseClass + '__meta__tag-label--posted'
              ]
            }, this.labels.article.posted),
            this.createElement('dd', {
              'class': [
                articleBaseClass + '__meta__tag-value',
                articleBaseClass + '__meta__tag-value--posted'
              ]
            }, this.createElement('time', {
              'datetime': fields.date.created
            }, this.createDate(fields.date.created, false).format('D MMM YYYY')))
          ]);

          const footer = this.createElement('footer', {
            'class': articleBaseClass + '__footer'
          }, meta);

          // Add the article.
          return this.createElement('article', {
            'class': articleBaseClass
          }, [
            header,
            footer
          ]);
        });

        this.riverList.replaceChildren(...articles);
      }
    }

    /**
     * Populate the river list from the API data.
     *
     * @param {Object} data
     *   Data from the ReliefWeb API.
     */
    updateRiverResults(data) {
      if (data && Array.isArray(data.data) && data.data.length > 0) {
        const offset = data.offset || 0;
        const replacements = {
          '_start_': offset + 1,
          '_end_': offset + data.count,
          '_total_': data.totalCount
        };
        const components = this.labels.riverResults.split(/(_start_|_end_|_total_)/g).map(component => {
          if (replacements.hasOwnProperty(component)) {
            return this.createElement('span', {}, this.numberFormatter.format(replacements[component]));
          }
          return document.createTextNode(component);
        });
        this.riverResults.replaceChildren(...components);
      }
    }

    /**
     * Display the no results message and empty the river list.
     */
    displayNoResults() {
      this.riverResults.replaceChildren(this.labels.riverNoResults);
      this.riverList.replaceChildren();
      this.toggleLoading(false);
    }

    /**
     * Parse the current advanced search parameters and create relevant
     * selected filters.
     */
    async parseAdvancedSearch() {
      const parameter = this.parameters.get('advanced-search');
      if (!parameter) {
        return null;
      }

      // Validate.
      const validationPattern = /^(((^|[._])!?)\(([A-Z]+(-?\d+|\d+-\d*|[0-9a-z-]+)([._](?!\)))?)+\))+$/;
      if (!validationPattern.test(parameter)) {
        return null;
      }

      // Parse the parameter.
      const parsingPattern = /(!?\(|\)[._]!?\(|[._])([A-Z]+)(\d+-\d*|-?\d+|[0-9a-z-]+)/g;
      const matches = parameter.matchAll(parsingPattern);

      const filters = [];
      const references = {};

      for (const match of matches) {
        const code = match[2];

        const definition = this.filterMap[code];
        // @todo we may need to fix the operator in that case.
        if (!definition) {
          continue;
        }

        const type = definition.type;
        const resource = definition.widget.resource || null;
        const operator = this.operatorMap[match[1]];
        const value = match[3];

        // Compute the label for the value.
        let label = value;
        if (type === 'reference') {
          // For fixed values, we can retrieve the label from the options.
          if (definition.widget.type === 'options') {
            // @todo we should discard the value if it's not found and then
            // fix the operators.
            label = definition.widget.options.find(item => item.id == value).name;
          }
          // For autocomplete, we store the value and we'll retrieve the values
          // from the API.
          else {
            if (!references[resource]) {
              references[resource] = {
                values: {},
                shortname: definition.shortname
              };
            }
            references[resource].values[value] = value;
          }
        }
        else if (type === 'fixed') {
          label = definition.values[value];
        }
        else if (type === 'date') {
          const [from, to] = value.split('-');
          label = this.formatDateRangeLabel(from, typeof to === 'undefined' ? from : to);
        }

        filters.push({field: definition, value, label, operator});
      }

      const labels = await this.getReferenceLabels(references);

      // Empty the current selection.
      this.emptyFilterSelection();

      // Add the filters from the advanced search.
      filters.forEach(filter => {
        const resource = filter.field.widget.resource;
        if (resource && labels[resource] && labels[resource][filter.value]) {
          filter.label = labels[resource][filter.value];
        }
        this.createSelectedFilter(filter.field, filter.value, filter.label, filter.operator, false, false);
      });

      this.filterSelection.setAttribute('data-selection', filters.length);
    }

    /**
     * Retrieve the label for the reference selected filters.
     *
     * @todo cache the labels?
     *
     * @param {Object} references
     *   Map of filter code with selected filter values.
     *
     * @return {Object}
     *   Map of filter code to filter values to labels.
     */
    async getReferenceLabels(references) {
      const resources = [];
      const promises = [];
      for (const resource in references) {
        if (references.hasOwnProperty(resource)) {
          const reference = references[resource];
          const url = this.apiUrl.replace('/reports', '/' + resource);
          const payload = {
            fields: {
              include: [
                'id',
                'name'
              ]
            },
            filter: {
              field: 'id',
              value: Object.getOwnPropertyNames(reference.values)
            }
          };
          if (reference.shortname) {
            payload.fields.include.push('shortname');
          }
          const promise = fetch(url, {
            method: 'POST',
            body: JSON.stringify(payload)
          })
          .then(response => {
            if (response.ok) {
              return response.json();
            }
            else {
              throw new Error('Failure');
            }
          })
          .then(json => {
            if (json && json.data) {
              const labels = {};
              json.data.forEach(item => {
                const fields = item.fields;
                let label = fields.name;
                if (fields.shortname && fields.shortname !== fields.name) {
                  label += ' (' + fields.shortname + ')';
                }
                labels[item.id] = label;
              });
              return labels;
            }
            else {
              throw new Error('Invalid');
            }
          })
          .catch(error => {
            return {};
          });
          resources.push(resource);
          promises.push(promise);
        }
      }
      const results = await Promise.allSettled(promises);
      const labels = {};
      results.forEach((result, index) => {
        labels[resources[index]] = result.value;
      });
      return labels;
    }

    /** ***** CREATE WIDGETS ****** **/

    /**
     * Create the whole widget wrapper.
     *
     * @return {Element}
     *   The wrapper element.
     */
    createWrapper() {
      const summary = this.createElement('summary', {
        'class': this.baseClass + '-wrapper__title'
      }, this.labels.wrapperTitle);

      this.wrapper = this.createElement('details', {
        'class': this.baseClass + '-wrapper'
      }, [
        summary,
        this.createSearchContainer(),
        this.createFilterContainer(),
        this.createRiverContainer()
      ]);

      if (this.openWrapper) {
        this.wrapper.setAttribute('open', '');
      }

      this.element.after(this.wrapper);

      return this.wrapper;
    }

    /**
     * Create the search section.
     *
     * @return {Element}
     *   The search container.
     */
    createSearchContainer() {
      const title = this.createElement('h' + this.headingLevel, {
        'id': this.baseId + '-search-title',
        'class': this.baseClass + '__search__title'
      }, this.labels.searchSectionTitle);

      const label = this.createElement('label', {
        'for': this.baseId + '-search-input',
        'class': this.baseClass + '__search__label'
      }, this.labels.searchLabel);

      this.searchInput = this.createElement('input', {
        'id': this.baseId + '-search-input',
        'class': this.baseClass + '__search__input',
        'type': 'search',
        'placeholder': this.labels.searchLabel
      });

      this.searchInput.addEventListener('keyup', event => {
        this.updateRiverUrl();
      });

      this.searchButton = this.createButton({
        'id': this.baseId + '-search-button',
        'class': this.baseClass + '__search__button'
      }, this.labels.searchButton);

      this.searchButton.addEventListener('click', event => {
        this.updateRiver();
      });

      const wrapper = this.createElement('div', {
        'class': this.baseClass + '__search__wrapper'
      }, [
        label,
        this.searchInput,
        this.searchButton
      ]);

      this.searchContainer = this.createElement('section', {
        'class': this.baseClass + '__search'
      }, [
        title,
        wrapper
      ]);

      return this.searchContainer;
    }

    /**
     * Create the river section.
     *
     * @return {Element}
     *   The river container.
     */
    createRiverContainer() {
      const title = this.createElement('h' + this.headingLevel, {
        'id': this.baseId + '-river-title',
        'class': [
          this.baseClass + '__river__title',
          'visually-hidden'
        ]
      }, this.labels.riverSectionTitle);

      this.riverResults = this.createElement('div', {
        'class': this.baseClass + '__river__results'
      });

      this.riverList = this.createElement('div', {
        'class': this.baseClass + '__river__list'
      });

      this.riverContainer = this.createElement('section', {
        'class': this.baseClass + '__river'
      }, [
        title,
        this.riverResults,
        this.riverList
      ]);

      return this.riverContainer;
    }

    /**
     * Create the filter widget container.
     *
     * @return {Element}
     *   The filter container.
     */
    createFilterContainer() {
      this.filterContainer = this.createElement('section', {
        'id': this.baseId,
        'class': this.baseClass,
        'data-advanced-mode': false,
        'data-empty': true
      }, [
        this.createElement('h' + this.headingLevel, {
          'id': this.baseId + '-title',
          'class': this.baseClass + '__title'
        }, this.labels.filterSectionTitle)
      ]);

      if (this.searchHelp) {
        this.filterContainer.appendChild(this.createElement('a', {
          'href': this.searchHelp,
          'target': '_blank',
          'rel': 'noopener noreferrer',
          'class': this.baseClass + '__help'
        }, this.labels.searchHelp));
      }

      this.filterContainer.appendChild(this.createFilterContent());

      return this.filterContainer;
    }

    /**
     * Create the content of the filter widget.
     *
     * @return {Element}
     *   The filter content wrapper.
     */
    createFilterContent(type) {
      this.filterContent = this.createElement('div', {
        'id': this.baseId + '-filter-content',
        'class': this.baseClass + '__filter__content'
      }, [
        this.createAnnouncement('selection'),
        this.createFilterSelection(),
        this.createAnnouncement('filter'),
        this.createFilterList()
      ]);
      return this.filterContent;
    }

    /**
     * Create a DOM element used to announce changes.
     *
     * @return {Element}
     *   DOM element.
     */
    createAnnouncement(type) {
      const element = this.createElement('div', {
        'id': this.baseId + '-' + type + '-announcement',
        'class': 'visually-hidden',
        'aria-live': 'polite'
      });
      return element;
    }

    /**
     * Create the filter selection section.
     *
     * @return {Element}
     *   DOM element.
     */
    createFilterSelection() {
      this.filterSelection = this.createElement('section', {
        'id': this.baseId + '-selection',
        'class': this.baseClass + '__selection'
      }, [
        this.createElement('h' + (this.headingLevel + 1), {
          'class': 'visually-hidden'
        }, this.labels.filterSelectionTitle)
      ]);

      // Parse the advanced search paramerer and create the selected filters.
      this.parseAdvancedSearch();

      // Remove a selected filter when clicking on its remove button
      // and update the operators to ensure consistency.
      this.filterSelection.addEventListener('click', event => {
        const target = event.target;
        if (target.nodeName === 'BUTTON' && target.parentNode.hasAttribute('data-value')) {
          const filter = target.parentNode;
          const field = this.getText(filter.querySelector('.' + this.baseClass + '__selected-filter__field'));
          const label = this.getText(filter.querySelector('.' + this.baseClass + '__selected-filter__label'));

          this.filterSelection.removeChild(target.parentNode.parentNode);
          this.updateOperatorSwitchers();

          // Announce the filter removal and the resulting selection.
          if (this.filterSelection.querySelector('[data-value]') !== null) {
            this.announce('selection', this.announcements.removeFilter, {
              '_field_': field,
              '_label_': label,
              '_selection_': this.readSelection(this.filterSelection)
            });
          }
          else {
            this.announce('selection', this.announcements.removeFilterEmpty, {
              '_field_': field,
              '_label_': label
            });
          }
        }
      });

      return this.filterSelection;
    }

    /**
     * Create the filter list.
     *
     * @return {Element}
     *   DOM element.
     */
    createFilterList() {
      this.filterList = this.createElement('section', {
        'id': this.baseId + '-filter-list',
        'class': this.baseClass + '__filter-list'
      }, [
        this.createElement('h' + (this.headingLevel + 1), {
          'class': 'visually-hidden'
        }, this.labels.filterListTitle),
        this.createCombinedFilter(),
        this.createActions(),
        this.createSimplifiedFilters(),
        this.createFilterSelector(),
        this.createAdvancedModeSwitch()
      ]);
      return this.filterList;
    }

    /**
     * Create the combined filter selector toggler.
     *
     * @return {Element}
     *   The filter selector toggler.
     */
    createCombinedFilter() {
      // Button to display the visibility of the filter selector.
      const toggler = this.createButton({
        'class': this.baseClass + '__filter-toggler',
        'data-toggler': 'combined',
        'data-hidden': false
      }, [
        this.createElement('span', {
          'class': this.baseClass + '__filter-list__label'
        }, this.labels.addFilter),
        this.createElement('span', {
          'class': this.baseClass + '__filter-list__label-suffix'
        }, this.labels.addFilterSuffix)
      ]);

      // Show/hide the dialog and the toggler.
      toggler.addEventListener('click', event => {
        const focusTarget = this.advancedMode ? this.operatorSelector : this.fieldSelector;

        // Move the dialog to the parent container.
        toggler.parentNode.appendChild(this.dialog);

        this.activeToggler = toggler;
        this.toggleDialog(focusTarget);
      });

      return toggler;
    }

    /**
     * Create the form action buttons.
     *
     * @return {Element}
     *   The container of the actions.
     */
    createActions() {
      const title = this.createElement('h' + (this.headingLevel + 1), {
        'class': 'visually-hidden'
      }, this.labels.formActions);

      // Create the clear and apply filters buttons and keep track of them so
      // they can be hidden/shown depending on the filter selection.
      this.clear = this.createButton({
        'class': this.baseClass + '__action',
        'data-clear': ''
      }, this.labels.clear);
      this.apply = this.createButton({
        'class': this.baseClass + '__action',
        'data-apply': ''
      }, this.labels.apply);

      // Clear advanced search.
      // @todo review how to clear the selection.
      this.clear.addEventListener('click', event => {
        this.emptyFilterSelection();
        this.updateRiver();
      });

      // Apply advanced search filter selection.
      this.apply.addEventListener('click', event => {
        this.updateRiver();
      });

      return this.createElement('section', {
        'class': this.baseClass + '__actions'
      }, [title, this.clear, this.apply]);
    }

    /**
     * Create simplified filters which are buttons that open the filter dialog.
     *
     * @return {Element}
     *   The container of the simplified filters.
     */
    createSimplifiedFilters() {
      const container = this.createElement('div', {
        'id': this.baseId + '-simplified-filters',
        'class': this.baseClass + '__simplified-filters'
      });

      // Create a button for each filter that will display the filter selection
      // dialog when clicked.
      const label = this.labels.simplifiedFilter;
      this.filters.forEach(filter => {
        const button = this.createButton({
          'aria-label': label.replace('_filter_', filter.name),
          'class': this.baseClass + '__filter-toggler',
          'data-hidden': false,
          'data-toggler': 'single',
          'data-field': filter.code,
          'data-operator': (filter.operator || 'or').toLowerCase()
        }, [
          this.createElement('span', {
            'class':  this.baseClass + '__filter-toggler__label'
          }, filter.name)
        ]);

        container.appendChild(this.createElement('div', {}, button));
      });

      // Handle button clicks inside the container.
      container.addEventListener('click', event => {
        let target = event.target;

        if (target.nodeName === 'SPAN') {
          target = target.parentNode;
        }

        if (target.nodeName === 'BUTTON' && target.getAttribute('data-toggler') === 'single') {
          // Hide the dialog and show the previous toggler.
          this.toggleDialog();

          // Move the dialog to the parent container.
          target.parentNode.appendChild(this.dialog);

          // Change the field selector.
          this.fieldSelector.value = target.getAttribute('data-field');

          // Change the operator selector depending on the mode.
          if (!this.advancedMode) {
            this.operatorSelector.value = target.getAttribute('data-operator');
          }
          else {
            this.updateOperatorSelector();
          }

          // Switch the widget.
          const widget = this.switchWidget();

          // Focus the operator selector or the field input/select in simplified
          // mode.
          var focusTarget = this.operatorSelector;
          if (!this.advancedMode) {
            focusTarget = widget.element.querySelector('input, select');
          }

          // Keep track of the active toggler for the dialog.
          this.activeToggler = target;

          // Show the dialog.
          this.toggleDialog(focusTarget);
        }
      });

      this.simplifiedFilters = container;

      return container;
    }

    /**
     * Create the filter selector container.
     *
     * @return {Element}
     *   The filter selector container.
     */
    createFilterSelector() {
      // Button to cancel or add a filter.
      const cancel = this.createButton({
        'class': this.baseClass + '__filter-selector__button',
        'data-cancel': ''
      }, this.labels.cancel);
      const add = this.createButton({
        'class': this.baseClass + '__filter-selector__button',
        'data-add': ''
      }, this.labels.add);

      const id = this.baseId + '-filter-selector-title';

      // Filter selector dialog container.
      const dialog = this.createElement('div', {
        'id': this.baseId + '-filter-selector',
        'role': 'dialog',
        'aria-modal': false,
        'aria-labelledby': id,
        'data-hidden': true,
        'class': this.baseClass + '__filter-selector'
      }, [
        this.createElement('h' + (this.headingLevel + 1), {
          'id': id,
          'class': this.baseClass + '__filter-selector__title'
        }, this.labels.filterSelector),
        this.createOperatorSelector(),
        this.createFieldSelector(),
        this.createWidgetList(),
        this.createElement('div', {}, [cancel, add])
      ]);

      // Keep track of the buttons and dialog.
      this.dialog = dialog;
      this.dialogAdd = add;
      this.dialogCancel = cancel;

      // Cancel the filter addition, clear the widget and close the dialog.
      cancel.addEventListener('click', event => {
        const widget = this.getActiveWidget();

        widget.clear();
        this.toggleDialog();
      });

      // Add a filter, clear the widget and close the dialog.
      add.addEventListener('click', event => {
        var widget = this.getActiveWidget();
        var value = widget.value();
        var label = widget.label();

        if (value) {
          var operator = this.operatorSelector.value;
          var field = this.getSelectedField();
          this.createSelectedFilter(field, value, label, operator);
        }

        widget.clear();
        this.toggleDialog();
      });

      // Cancel when pressing escape and wrap focus inside the dialog.
      dialog.addEventListener('keydown', event => {
        const key = event.which || event.keyCode;

        // Close the dialog when pressing escape.
        if (key === this.keyCodes.ESC) {
          event.preventDefault();
          this.triggerEvent(cancel, 'click');
        }
        // Wrap the focus inside the dialog.
        else if (key === this.keyCodes.TAB) {
          let firstInteractiveElement;
          if (this.advancedMode) {
            firstInteractiveElement = dialog.querySelector('input, select');
          }
          else {
            firstInteractiveElement = this.getActiveWidget().element.querySelector('input, select');
          }
          if (!event.shiftKey && event.target === add) {
            event.preventDefault();
            event.stopPropagation();
            firstInteractiveElement.focus();
          }
          else if (event.shiftKey && event.target === firstInteractiveElement) {
            event.preventDefault();
            event.stopPropagation();
            add.focus();
          }
        }
      });

      return dialog;
    }

    /**
     * Create the operator selector.
     *
     * @return {DocumentFragment}
     *   The operator selector wrapped in a document fragment.
     */
    createOperatorSelector() {
      const id = this.baseId + '-operator-selector';

      const select = this.createElement('select', {
        'id': id,
        'class': [
          this.baseClass + '__operator-selector',
          this.baseClass + '__widget__select'
        ]
      });
      const label = this.createLabel(id, this.labels.operatorSelector, {
        'class': this.baseClass + '__operator-selector-label'
      });

      const labels = this.labels.operators;
      this.operators.forEach(group => {
        const options = group.options;
        const optgroup = this.createElement('optgroup', {label: group.label});
        options.forEach(option => {
          optgroup.appendChild(this.createOption(option, labels[option]));
        });
        select.appendChild(optgroup);
      });

      // Keep track of the operator selector as it's used in many places.
      this.operatorSelector = select;

      return this.createFragment(label, select);
    }

    /**
     * Create the field selector.
     *
     * @return {DocumentFragment}
     *   The field selector wrapped in a document fragment.
     */
    createFieldSelector() {
      const id = this.baseId + '-field-selector';

      const options = [];
      this.filters.forEach(filter => {
        options.push(this.createOption(filter.code, filter.name, {
          'class': this.baseClass + '__field-selector__option'
        }));
      });
      const select = this.createElement('select', {
        'id': id,
        'class': [
          this.baseClass + '__field-selector',
          this.baseClass + '__widget__select'
        ]
      }, options);
      const label = this.createLabel(id, this.labels.fieldSelector, {
        'class': this.baseClass + '__field-selector-label'
      });

      // Keep track of the field selector as it's used in many places.
      this.fieldSelector = select;

      // Switch widget when changing the field.
      select.addEventListener('change', event => {
        this.switchWidget(true);
      });

      return this.createFragment(label, select);
    }

    /**
     * Create the list of widget form elements.
     *
     * @return {Element}
     *   The container of the widgets.
     */
    createWidgetList() {
      const container = this.createElement('div', {
        'id': this.baseId + '-widget-list'
      });

      this.filters.forEach(filter => {
        switch (filter.widget.type) {
          case 'autocomplete':
            container.appendChild(this.createAutocompleteWidget(filter));
            break;

          case 'keyword':
            container.appendChild(this.createKeywordWidget(filter));
            break;

          case 'options':
            container.appendChild(this.createOptionsWidget(filter));
            break;

          case 'date':
            container.appendChild(this.createDateWidget(filter));
            break;
        }
      });

      // Keep track of widget list as it's used in many places.
      this.widgetList = container;

      return container;
    }

    /**
     * Create a widget wrapper.
     *
     * @param {Object} filter
     *   Filter definition.
     * @param {String|Element|Array} content
     *   Content of the wrapper.
     *
     * @return {Element}
     *   Widget wrapper element.
     */
    createWidgetWrapper(filter, content, legend = true) {
      if (legend) {
        content.unshift(this.createElement('legend', {
          'class': [
            this.baseClass + '__widget__legend',
            'visually-hidden'
          ]
        }, this.labels.filter.replace('_filter_', filter.name)));
      }

      return this.createElement('fieldset', {
        'class': this.baseClass + '__widget',
        'data-code': filter.code,
        'data-widget': filter.widget.type
      }, content);
    }

    /**
     * Create an autocomplete widget.
     *
     * @param {Object} filter
     *   Filter definition.
     *
     * @return {Element}
     *   Widget wrapper element.
     */
    createAutocompleteWidget(filter) {
      const id = this.baseId + '-autocomplete-widget-' + filter.code;

      const input = this.createElement('input', {
        'id': id,
        'class': this.baseClass + '__widget__input',
        'type': 'search',
        'autocomplete': 'off',
        'placeholder': this.placeholders.autocomplete,
        'data-with-autocomplete': filter.widget.url
      });

      const label = this.createLabel(id, filter.widget.label, {
        'class': this.baseClass + '__widget__label'
      });

      return this.createWidgetWrapper(filter, [label, input]);
    }

    /**
     * Create a keyword widget.
     *
     * @param {Object} filter
     *   Filter definition.
     *
     * @return {Element}
     *   Widget wrapper element.
     */
    createKeywordWidget(filter) {
      const id = this.baseId + '-keyword-widget-' + filter.code;

      const input = this.createElement('input', {
        'id': id,
        'class': this.baseClass + '__widget__input',
        'type': 'search',
        'autocomplete': 'off',
        'placeholder': this.placeholders.keyword
      });

      const label = this.createLabel(id, filter.widget.label, {
        'class': this.baseClass + '__widget__label'
      });

      return this.createWidgetWrapper(filter, [label, input]);
    }

    /**
     * Create options widget.
     *
     * @param {Object} filter
     *   Filter definition.
     *
     * @return {Element}
     *   Widget wrapper element.
     */
    createOptionsWidget(filter) {
      const id = this.baseId + '-options-widget-' + filter.code;

      const options = [this.createOption('', this.labels.emptyOption, {
        'class': this.baseClass + '__widget__option',
        'selected': 'selected'
      })];
      filter.widget.options.forEach(option => {
        options.push(this.createOption(option.id, option.name));
      });

      const select = this.createElement('select', {
        'id': id,
        'class': this.baseClass + '__widget__select'
      }, options);

      const label = this.createLabel(id, filter.widget.label, {
        'class': this.baseClass + '__widget__label'
      });

      return this.createWidgetWrapper(filter, [label, select]);
    }

    /**
     * Create date widget.
     *
     *
     * @param {Object} filter
     *   Filter definition.
     *
     * @return {Element}
     *   Widget wrapper element.
     */
    createDateWidget(filter) {
      const id = this.baseId + '-date-widget-' + filter.code;

      const content = [
        this.createElement('legend', {
          'class': this.baseClass + '__widget__legend'
        }, filter.widget.label),
        // From date selector.
        this.createLabel(id + '-from', this.labels.dateFrom, {
          'class': this.baseClass + '__widget__label'
        }),
        this.createElement('input', {
          'id': id + '-from',
          'class': this.baseClass + '__widget__input',
          'type': 'text',
          'autocomplete': 'off',
          'placeholder': this.placeholders.dateFrom,
          'data-from': '',
          'data-with-datepicker': '',
          'aria-label': filter.name + ' - ' + this.labels.dateFrom
        }),
        // To date selector.
        this.createLabel(id + '-to', this.labels.dateTo, {
          'class': this.baseClass + '__widget__label'
        }),
        this.createElement('input', {
          'id': id + '-to',
          'class': this.baseClass + '__widget__input',
          'type': 'text',
          'autocomplete': 'off',
          'placeholder': this.placeholders.dateTo,
          'data-to': '',
          'data-with-datepicker': '',
          'aria-label': filter.name + ' - ' + this.labels.dateTo
        })
      ];

      return this.createWidgetWrapper(filter, content, false);
    }

    /**
     * Create a filter selection.
     *
     * @param {Object} field
     *   Field definition.
     * @param {String} value
     *   Field value.
     * @param {String} label
     *   Field label.
     * @param {String} operator
     *   Operator.
     * @param {Boolean} update
     *   If TRUE update the operator switchers to ensure consistency.
     * @param {Boolean} announce
     *   If TRUE announce the change to the filter selection.
     */
    createSelectedFilter(field, value, label, operator, update = true, announce = true) {
      let previous = null;

      // In simplified mode, get the filter after which to insert the new one.
      if (!this.advancedMode) {
        previous = this.getPreviousSimplifiedFilter(field, value);
        // If null, then it means this new filter is the first selected one.
        if (previous === null) {
          operator = 'with';
        }
        // Otherwise if the previous filter doesn't have the same field code,
        // we create a new group.
        else if (previous && previous.getAttribute('data-field') !== field.code) {
          operator = 'and-with';
        }
      }

      // If previous is false, then it means the filter already exists and
      // we skip the creation.
      if (previous !== false) {
        operator = this.createOperatorSwitcher(operator);

        const filter = this.createElement('div', {
          'class': this.baseClass + '__selected-filter',
          'data-value': field.code + value
        }, [
          this.createElement('span', {
            'class': this.baseClass + '__selected-filter__field'
          }, field.name + ': '),
          this.createElement('span', {
            'class':  this.baseClass + '__selected-filter__label'
          }, label),
          this.createButton({
            'class':  this.baseClass + '__selected-filter__remove'
          }, this.labels.remove)
        ]);

        const container = this.createElement('div', {
          'class': this.baseClass + '__selected-filter-container',
          'data-field': field.code,
          'aria-label': field.name
        }, [operator, filter]);

        // In simplified mode, insert the new filter after the previous one or at
        // the beginning.
        if (!this.advancedMode) {
          this.filterSelection.insertBefore(container, previous ? previous.nextSibling : this.filterSelection.firstChild);
        }
        else {
          this.filterSelection.appendChild(container);
        }

        // Ensure the other operators and the operator selector have
        // consistent values.
        if (update) {
          this.updateOperatorSwitchers();
        }
      }

      // Announce the added filter and the resulting full selection.
      if (announce) {
        this.announce('selection', this.announcements.addFilter, {
          '_field_': field.name,
          '_label_': label,
          '_selection_': this.readFilterSelection(this.filterSelection)
        });
      }
    }


    /**
     * Create the checkbox to active advanced search mode.
     *
     * @return {Element}
     *   The container for the checkbox.
     */
    createAdvancedModeSwitch() {
      // Check if the advanced mode is on based on the filter selection.
      const advancedMode = this.isAdvancedMode();

      // Keep track of the mode.
      this.filterContainer.setAttribute('data-advanced-mode', advancedMode);
      this.advancedMode = advancedMode;

      const id = this.baseId + '-advanced-mode-switch';

      const checkbox = this.createElement('input', {
        'id': id,
        'class': this.baseId + '__advanced-mode-switch__checkbox',
        'type': 'checkbox'
      });
      checkbox.checked = advancedMode;

      const label = this.createElement('label', {
        'class': this.baseId + '__advanced-mode-switch__label',
        'for': id
      }, this.labels.advancedMode);

      const link = this.filterContainer.querySelector('[href$="/search-help"]').cloneNode(true);
      this.setText(link, this.getText(link) + ' - ' + this.labels.advancedMode);
      link.setAttribute('href', link.getAttribute('href') + '#advanced');

      const container = this.createElement('div', {
        'id': id + '-container',
        'class': this.baseClass + '__advanced-mode-switch-container'
      }, [checkbox, label, link]);

      checkbox.addEventListener('click', event => {
        const enabled = checkbox.checked;

        // Clear the selection when switching to simplified mode as it's not
        // compatible with the complex queries of the advanced mode.
        if (!enabled && !this.filterContainer.hasAttribute('data-empty')) {
          if (window.confirm(this.labels.changeMode)) {
            this.triggerEvent(this.clear, 'click');
          }
          else {
            event.preventDefault();
          }
        }
        else {
          // Update the operator selectors when switching to advanced mode.
          this.filterContainer.setAttribute('data-advanced-mode', enabled);
          this.advancedMode = enabled;
          this.createOperatorSwitchers();
        }
      });

      return container;
    }

    /**
     * Create the operator switchers for the operators in the current selection.
     */
    createOperatorSwitchers() {
      // Update the operator switchers in the selection.
      const elements = this.filterSelection.querySelectorAll('[data-operator]');
      if (elements.length > 0) {
        for (const element of elements) {
          this.createOperatorSwitcher(element);
        }

        // Update the operators.
        this.updateOperatorSwitchers();
      }
    }

    /**
     * Create an operator switcher.
     *
     * @param {Element|String} element
     *   Operator element or name.
     *
     * @return {Element}
     *   The new operator element.
     */
    createOperatorSwitcher(element) {
      const options = this.labels.operators;
      var id = this.baseId + '-operator-' + this.maxId++;

      if (typeof element === 'string') {
        element = this.createElement('div', {
          'data-operator': element
        }, options[element]);
      }

      const operator = element.getAttribute('data-operator');

      const label = options[operator];

      const button = this.createButton({
        'id': id + '-button',
        'class': this.baseClass + '__operator-switcher',
        'aria-haspopup': 'listbox',
        'aria-label': this.labels.switchOperator.replace('_operator_', label),
        'aria-expanded': false
      }, label);

      const list = this.createElement('ul', {
        'class': this.baseClass + '__operator-switcher__list',
        'role': 'listbox',
        'tabIndex': -1,
        'data-hidden': true
      });

      for (const option in options) {
        if (options.hasOwnProperty(option)) {
          list.appendChild(this.createElement('li', {
            'class': this.baseClass + '__operator-switcher__list__item',
            'id': id + '-' + option,
            'role': 'option',
            'data-option': option
          }, options[option]));
        }
      }

      // Show/hide the list when the button is clicked or select list item when
      // it is clicked.
      // @todo prevent selection of options with aria-disabled set to true.
      element.addEventListener('click', event => {
        const target = event.target;
        if (target.nodeName === 'BUTTON') {
          const expand = target.getAttribute('aria-expanded') === 'false';
          this.toggleOperatorSwitcher(button, list, expand);
        }
        else if (target.nodeName === 'LI') {
          this.updateOperatorSwitcher(element, target);
          this.toggleOperatorSwitcher(button, list, false);
          // Ensure the other operators and the operator selector have
          // consistent values.
          this.updateOperatorSwitchers();
        }
      });

      // Basic keyboard support.
      // @todo handle looking for item with first letters.
      element.addEventListener('keydown', event => {
        const target = event.target;
        const key = event.which || event.keyCode;

        if (target.nodeName === 'UL') {
          event.preventDefault();
          let selection = null;

          switch (key) {
            case this.keyCodes.UP:
              selection = element.querySelector('[aria-selected]').nextElementSibling;
              break;

            case this.keyCodes.DOWN:
              selection = element.querySelector('[aria-selected]').nextElementSibling;
              break;

            case this.keyCodes.HOME:
              selection = list.firstChild;
              break;

            case this.keyCodes.END:
              selection = list.lastChild;
              break;

            case this.keyCodes.ENTER:
            case this.keyCodes.ESC:
              this.toggleOperatorSwitcher(button, list, false);
              return;
          }
          if (selection) {
            this.toggleOperatorSwitcher(button, list, true);
            this.updateOperatorSwitcher(element, selection);
          }
        }
        else if (target.nodeName === 'BUTTON' && key === this.keyCodes.ENTER) {
          event.preventDefault();
          this.toggleOperatorSwitcher(button, list, true);
        }
      });

      element.setAttribute('id', id);
      if (element.firstChild) {
        element.replaceChild(button, element.firstChild);
      }
      else {
        element.appendChild(button);
      }
      element.appendChild(list);

      this.updateOperatorSwitcher(element, operator);
      return element;
    }

    /** ***** FILTER ***** **/

    /**
     * Get the selected filter before a new filter.
     *
     * This is used to find where to insert the new filter in simplified mode.
     *
     * @param {Object} field
     *   Field definition.
     * @param {String} value
     *   Field value
     *
     * @return {Element|False|Null}
     *   False if the filter already exists or the previous filter element
     *   or null if undefined.
     */
    getPreviousSimplifiedFilter(field, value) {
      // Skip if the filter already exists.
      if (this.filterSelection.querySelector('[data-value="' + field.code + value + '"]') !== null) {
        return false;
      }

      // Store the order of the filters so that the added values are in the
      // same order, which helps readability of the filter selection in
      // simplified mode.
      const indices = {};
      this.filters.forEach((filter, index) => {
        indices[filter.code] = index;
      });

      const currentFieldIndex = indices[field.code];

      // Find the element after which to insert the new filter.
      const elements = this.filterSelection.querySelectorAll('[data-field]');
      for (const element of elements) {
        if (indices[element.getAttribute('data-field')] <= currentFieldIndex) {
          return element;
        }
      }
      return null;
    }

    /** ***** FORM MANIPULATION ***** **/

    /**
     * Toggle the visibility of a dialog.
     *
     * @param {Element} focusTarget
     *   The element to focus.
     */
    toggleDialog(focusTarget) {
      if (this.activeToggler) {
        const close = this.dialog.getAttribute('data-hidden') !== 'true';
        this.dialog.setAttribute('data-hidden', close);
        this.activeToggler.setAttribute('data-hidden', !close);

        if (close) {
          this.activeToggler.removeAttribute('tabindex');
          this.activeToggler.focus();
          this.activeToggler = null;
        }
        else if (focusTarget) {
          this.activeToggler.setAttribute('tabindex', -1);
          focusTarget.focus();
        }
      }
    }

    /**
     * Active the widget corresponding to the current selected field's code.
     *
     * @param {Boolean} announce
     *   Whether to announce the change for widget or not.
     *
     * @return {Element}
     *   The new active widget.
     */
    switchWidget(announce) {
      const field = this.getSelectedField();
      const code = field.code;
      let active = null;
      this.widgets.forEach(widget => {
        if (widget.element.getAttribute('data-code') === code) {
          widget.enable();
          active = widget;
        }
        else {
          widget.disable();
        }
      });

      if (announce === true) {
        this.announce('filter', this.announcements.changeFilter, {
          '_name_': field.name
        });
      }

      return active;
    }

    /**
     * Get active widget.
     *
     * @return {Object}
     *   The active widget or null.
     */
    getActiveWidget() {
      return this.widgets.find(widget => {
        return widget.active();
      }) || null;
    }

    /**
     * Get the current field.
     *
     * @return {Object}
     *   Object with the selected field name and code.
     */
    getSelectedField() {
      const fields = this.fieldSelector.querySelectorAll('option');
      const name = this.getText(fields[this.fieldSelector.selectedIndex]);
      return {
        name: name,
        code: this.fieldSelector.value
      };
    }

    /**
     * Announce a change to the form or filter selection.
     *
     * @param {String} type
     *   Type of the announcement.
     * @param {String} text
     *   Text of the announcement.
     * @param {Object} replacements
     *   Text replacments.
     */
    announce(type, text, replacements) {
      // @todo keep track of the element.
      const live = document.getElementById(this.baseId + '-' + type + '-announcement');
      if (live) {
        for (const key in replacements) {
          if (replacements.hasOwnProperty(key)) {
            text = text.replace(key, replacements[key]);
          }
        }
        live.innerHTML = text;
      }
    }

    /**
     * Detect filter mode (simplified or advanced).
     *
     * @return {Boolean}
     *   True if in advanced mode.
     */
    isAdvancedMode() {
      let element = this.filterSelection.firstChild;
      let previous = null;
      while (element) {
        if (element.hasAttribute && element.hasAttribute('data-field')) {
          const operator = element.querySelector('[data-operator]').getAttribute('data-operator');
          const field = element.getAttribute('data-field');

          // Same fields separated by a group starting operator.
          if (field === previous && operator.indexOf('with') !== -1) {
            return true;
          }

          switch (operator) {
            // Those operators are only available in advanced mode.
            case 'or-with':
            case 'or-without':
            case 'and-without':
            case 'without':
              return true;

            // Mixed fields inside a group is only available in advanced mode.
            case 'or':
            case 'and':
              if (field !== previous) {
                return true;
              }
              break;
          }
          previous = field;
        }
        element = element.nextSibling;
      }
      // Simplified mode.
      return false;
    }

    /** ****** WIDGETS ****** **/

    /**
     * Create the autocomplete and datepicker widgets.
     */
    createWidgets() {
      this.widgets = [];

      const elements = this.widgetList.querySelectorAll('[data-code]');
      if (elements) {
        for (const element of elements) {
          this.widgets.push(this.createWidget(element));
        }
      }

      // Hide widgets when clicking outside of their containers.
      document.addEventListener('click', event => {
        var target = event.target;
        this.widgets.forEach(widget => {
          if (widget.active() && !widget.element.contains(target)) {
            widget.hide();
          }
        });
      });
    }

    /**
     * Create a widget.
     *
     * @param {Element} element
     *   Base element for the widget.
     *
     * @return {Object}
     *   The widget.
     */
    createWidget(element) {
      const widget = {
        handler: this,
        element: element,
        active: function () {
          return this.element.hasAttribute('data-active');
        },
        enable: function () {
          this.element.removeAttribute('disabled');
          this.element.removeAttribute('tabindex');
          this.element.setAttribute('data-active', '');
        },
        disable: function () {
          this.clear();
          this.element.setAttribute('disabled', '');
          this.element.setAttribute('tabindex', -1);
          this.element.removeAttribute('data-active');
        },
        hide: function () {
          // Noop for most widgets.
        }
      };

      switch (element.getAttribute('data-widget')) {
        case 'autocomplete':
          widget.autocomplete = this.createAutocomplete(element.querySelector('[data-with-autocomplete]'));
          widget.clear = function () {
            this.autocomplete.clear();
          };
          widget.value = function () {
            return this.autocomplete.value();
          };
          widget.label = function () {
            return this.autocomplete.label();
          };
          break;

        case 'date':
          widget.from = this.createDatepicker(element.querySelector('[data-from]'));
          widget.to = this.createDatepicker(element.querySelector('[data-to]'));

          // Close the other datepicker when opening a datepicker.
          widget.from.widget.on('opened', widget.to.widget.hide);
          widget.to.widget.on('opened', widget.from.widget.hide);

          widget.hide = function () {
            widget.from.widget.hide();
            widget.to.widget.hide();
          };
          widget.clear = function () {
            this.from.clear();
            this.to.clear();
          };
          widget.value = function () {
            return this.handler.formatDateRangeValue(this.from.value(), this.to.value());
          };
          widget.label = function () {
            return this.handler.formatDateRangeLabel(this.from.value(), this.to.value(), this.handler.labels.dates);
          };
          break;

        case 'keyword':
          widget.input = element.querySelector('input');
          widget.clear = function () {
            this.input.value = '';
          };
          widget.value = function () {
            return this.input.value.trim();
          };
          widget.label = function () {
            return this.input.value.trim();
          };
          break;

        case 'options':
          widget.select = element.querySelector('select');
          // Add the filter after selection when pressing enter.
          widget.select.addEventListener('keyup', function (event) {
            const key = event.which || event.keyCode;
            if (key === this.handler.keyCodes.ENTER && this.value) {
              this.handler.triggerEvent(this.dialogAdd, 'click');
            }
          });
          widget.clear = function () {
            this.select.selectedIndex = 0;
          };
          widget.value = function () {
            return this.select.value;
          };
          widget.label = function () {
            var options = this.select.querySelectorAll('option');
            return this.handler.getText(options[this.select.selectedIndex]);
          };
          break;
      }

      // Initial state is disabled.
      widget.disable();
      return widget;
    }

    /**
     * Create an autocomplete widget.
     *
     * @param {Element} element
     *   The DOM element to which attach the autocomplete.
     *
     * @return {Object}
     *   Autocomplete widget.
     */
    createAutocomplete(element) {
      const url = element.getAttribute('data-with-autocomplete');

      const parent = element.parentNode;
      parent.setAttribute('data-autocomplete', '');
      // @todo review the class.
      parent.classList.add(this.baseClass + '__autocomplete');

      const autocomplete = SimpleAutocomplete.autocomplete(element, url, {
        // @todo review the class.
        namespace: this.baseClass + '__autocomplete',
        filter: function (query, data) {
          data = typeof data === 'string' ? JSON.parse(data) : data;
          const suggestions = [];
          if (data && data.data) {
            data.data.forEach(item => {
              const fields = item.fields;
              const name = fields.name;
              const shortname = fields.shortname;
              let label = name;
              if (shortname && shortname !== name) {
                label += ' (' + shortname + ')';
              }
              suggestions.push({
                value: item.id,
                label: label
              });
            });
          }
          return suggestions;
        }
      });

      // Override the focus function so that we don't focus the input after
      // selecting a value as we close the dialog and focus the toggling button
      // instead.
      autocomplete.focus = function () {
        // No op.
      };

      // Add to the selection.
      autocomplete.on('selected', event => {
        var data = event.data;
        if (data) {
          element.setAttribute('data-value', data.value);
          element.setAttribute('data-label', data.label);
          element.value = data.label;
          this.triggerEvent(this.dialogAdd, 'click');
        }
        else {
          element.value = '';
        }
      });

      return {
        clear: function () {
          element.removeAttribute('data-value');
          element.removeAttribute('data-label');
          autocomplete.clear();
        },
        value: function () {
          return element.getAttribute('data-value') || '';
        },
        label: function () {
          return element.getAttribute('data-label') || '';
        }
      };
    }

    /**
     * Create a datepicker widget.
     *
     * @param {Element} element
     *   The DOM element to which attach the datepicker.
     *
     * @return {Object}
     *   Datepicker widget.
     */
    createDatepicker(input) {
      const parent = input.parentNode;
      parent.setAttribute('data-datepicker', '');
      parent.classList.add(this.baseClass + '__datepicker');
      input.classList.add(this.baseClass + '__datepicker-input');

      // Create the button to show the datepicker.
      const toggleLabel = this.createElement('span', {
        'class': [
          this.baseClass + '__datepicker-toggle-label',
          'visually-hidden'
        ]
      }, this.labels.chooseDate);
      const toggle = this.createButton({
        'data-datepicker-toggle': '',
        'class': this.baseClass + '__datepicker-toggle'
      }, toggleLabel);
      parent.insertBefore(toggle, input.nextSibling);

      // @todo change the simpledatepicker upstream to use some namespace like
      // the autocomplete widget rather than individual classes?
      const datepicker = SimpleDatePicker.datepicker({
        namespace: this.baseClass + '__datepicker',
        input: toggle,
        container: parent
      }).hide();

      // Fix the position of the datepicker so that it's just after the input.
      parent.insertBefore(datepicker.container, toggle.nextSibling);

      // Button to cancel or select the date.
      const cancel = this.createButton({
        'class': this.baseClass + '__widget__button',
        'data-cancel': ''
      }, this.labels.cancel);
      const select = this.createButton({
        'class': this.baseClass + '__widget__button',
        'data-select': ''
      }, this.labels.select);
      const buttonContainer = this.createElement('div', {
        'class': this.baseClass + '__widget__button-container'
      });
      buttonContainer.appendChild(cancel);
      buttonContainer.appendChild(select);
      datepicker.calendars[0].calendar.appendChild(buttonContainer);

      // Update the toggle label based on the selected date.
      const updateToggleLabel = date => {
        let label = this.labels.chooseDate;
        if (date) {
          label = this.labels.changeDate.replace('_date_', date.format('dddd D MMMM YYYY'));
        }
        this.setText(toggleLabel, label);
      };

      // Update the date input with the selected date.
      const updateDateInput = date => {
        if (date) {
          input.value = date.format('YYYY/MM/DD');
          input.setAttribute('data-value', date.format('YYYYMMDD'));
        }
        else {
          input.value = '';
          input.removeAttribute('date-value');
        }
        updateToggleLabel(date);
        datepicker.hide();
        toggle.focus();
      };

      // Cancel the filter addition, clear the widget and close the dialog.
      cancel.addEventListener('click', event => {
        datepicker.hide().clear();
        toggle.focus();
      });

      // Add a filter, clear the widget and close the dialog.
      select.addEventListener('click', event => {
        const focused = datepicker.getFocusedDay();
        if (focused) {
          datepicker.select(focused);
        }
        else {
          const selection = datepicker.getSelection();
          updateDateInput(selection.length ? selection[0] : null);
        }
      });

      // Update the date of the datepicker based on the value from the input.
      datepicker.on('opened', event => {
        this.updateDatepicker(datepicker, input.value);
      });

      // Update the input text when selecting a date.
      datepicker.on('select', event => {
        updateDateInput(event.data && event.data.length ? event.data[0] : null);
      });

      // Show/Hide the datepicker.
      toggle.addEventListener('click', event => {
        datepicker.toggle();
      });

      // Update the toggle label.
      input.addEventListener('keyup', event => {
        const date = this.updateDatepicker(datepicker, input.value);
        updateToggleLabel(date);
      });

      // Logic to keep the focus inside the dialog when it's open.
      const firstButton = datepicker.container.querySelector('button');
      select.addEventListener('keydown', event => {
        const key = event.which || event.keyCode;
        if (key === this.keyCodes.TAB && !event.shiftKey) {
          event.preventDefault();
          event.stopPropagation();
          firstButton.focus();
        }
      });
      firstButton.addEventListener('keydown', event => {
        const key = event.which || event.keyCode;
        if (key === this.keyCodes.TAB && event.shiftKey) {
          event.preventDefault();
          event.stopPropagation();
          select.focus();
        }
      });

      return {
        widget: datepicker,
        clear: function () {
          input.value = '';
          input.removeAttribute('data-value');
          datepicker.hide().clear();
        },
        value: function () {
          return input.getAttribute('data-value') || '';
        }
      };
    }

    /**
     * Update the datepicker date based on the input value.
     *
     * @param {Object} datepicker
     *   The datepicker widget.
     * @param {String} value
     *   The date value.
     *
     * @return {Object}
     *   The date object for the date value or null.
     */
    updateDatepicker(datepicker, value) {
      // Set the selected date from the value in the input field if valid.
      if (value && value.match(/^\d{4}([/-]\d{2}){0,2}$/)) {
        value = value.length === 4 ? value + '-01-01' : value;
        value = value.length === 7 ? value + '-01' : value;
        value = value.replaceAll('-', '/');
        const date = datepicker.createDate(value + ' UTC');
        if (!date.invalid()) {
          const calendar = datepicker.calendars[0];
          datepicker.setSelection([date], false).updateCalendar(calendar, date);
          return date;
        }
      }
      return null;
    }

    /** ***** SELECTION ***** **/

    /**
     * Update the filter count in the selection and hide/show the actions.
     *
     * @param {Boolean} update
     *   Whether to update the river URL or not.
     */
    updateFilterSelection(update = true) {
      const count = this.filterSelection.querySelectorAll('[data-value]').length;
      this.filterSelection.setAttribute('data-selection', count);

      // Remove the initial empty state from the filter Selection which is just
      // there to hide the action buttons.
      if (count > 0) {
        this.filterContainer.removeAttribute('data-empty');
      }

      // Get the filter selection.
      const advancedSearch = this.parseFilterSelection(this.filterSelection);
      if (advancedSearch) {
        this.parameters.set('advanced-search', advancedSearch);
      }
      else {
        this.parameters.delete('advanced-search');
      }

      // Update the river URL with the new parameters.
      if (update) {
        this.updateRiverUrl();
      }

      // In case of changes to the selection, update the apply button to notify
      // the user to click on it to update the list.
      this.apply.setAttribute('data-apply', advancedSearch !== this.initialAdvancedSearch);
    }

    /**
     * Empty the filter selection.
     */
    emptyFilterSelection() {
      const elements = this.filterSelection.querySelectorAll('[data-field]');
      for (const element of elements) {
        this.filterSelection.removeChild(element);
      }
      this.filterSelection.setAttribute('data-selection', 0);
    }

    /**
     * Generate the advanced search parameter from the filter selection.
     *
     * @param {Element} selection
     *   The DOM element container the filter selection.
     * @param {Boolean} nested
     *   Whether we are parsing a nested part of the filter selection.
     * @param {String} result
     *   The current result of the filter selection parsing.
     *
     * @return {String}
     *   The filter selection as a string.
     */
    parseFilterSelection(selection, nested, first) {
      let element = selection.firstChild;
      let result = '';
      while (element) {
        if (element.hasAttribute) {
          if (element.hasAttribute('data-field')) {
            result += this.parseFilterSelection(element, true, result === '');
          }
          else if (element.hasAttribute('data-operator')) {
            switch (element.getAttribute('data-operator')) {
              case 'and':
                result += '_';
                break;
              case 'or':
                result += '.';
                break;
              case 'or-with':
                result += ').(';
                break;
              case 'or-without':
                result += ').!(';
                break;
              case 'and-with':
                result += ')_(';
                break;
              case 'and-without':
                result += ')_!(';
                break;
              case 'with':
                result += '(';
                break;
              case 'without':
                result += '!(';
                break;
              case 'any':
              case 'all':
                result += first ? '(' : ')_(';
                break;
            }
          }
          else if (element.hasAttribute('data-value')) {
            result += element.getAttribute('data-value');
          }
        }
        element = element.nextSibling;
      }
      var suffix = nested !== true ? ')' : '';
      return result ? result + suffix : '';
    }

    /**
     * Parse the filter selection into a readable message.
     *
     * @param {Element} selection
     *   Filter selection DOM element.
     * @param {Boolean} first
     *   Whether this is the first part of the filter selection or not.
     *
     * @return string
     *   A human readable version of the selection.
     */
    readFilterSelection(selection, first) {
      var element = selection.firstChild;
      var parts = [];
      while (element) {
        if (element.hasAttribute) {
          if (element.hasAttribute('data-field')) {
            parts.push(this.readFilterSelection(element, parts.length === 0));
          }
          else if (element.hasAttribute('data-operator')) {
            var operator = element.getAttribute('data-operator');
            if (operator === 'any' || operator === 'all') {
              operator = first ? 'with' : 'and-with';
            }
            parts.push(operator.replace('-', ' '));
          }
          else if (element.hasAttribute('data-value')) {
            parts.push(this.getText(element.querySelector('.' + this.baseClass + '__selected-filter__field')));
            parts.push(this.getText(element.querySelector('.' + this.baseClass + '__selected-filter__label')));
          }
        }
        element = element.nextSibling;
      }
      return parts.join(' ');
    }

    /** ****** OPERATOR ****** **/

    /**
     * Update options for the operator selector based on the last operator
     * in the filter selection.
     *
     * @todo add some comments to explain the logic.
     */
    updateOperatorSelector() {
      const options = this.operatorSelector.querySelectorAll('option');
      const last = this.getLastOperator();
      const operators = this.getOperatorOptions(last);

      let optgroup = null;
      let selected = false;
      options.forEach(option => {
        if (!operators.hasOwnProperty(option.value)) {
          option.setAttribute('disabled', '');
          option.disabled = true;
        }
        else {
          option.removeAttribute('disabled');
          option.disabled = false;
          if (!selected) {
            selected = true;
            option.selected = true;
          }
        }

        // Disable group by default.
        if (optgroup !== option.parentNode) {
          optgroup = option.parentNode;
          optgroup.disabled = true;
          optgroup.setAttribute('disabled', '');
        }
        // Mark the group as enabled if at least on of its options is enabled.
        if (option.disabled === false) {
          optgroup.removeAttribute('disabled', '');
          optgroup.disabled = false;
        }
      });
    }

    /**
     * Get the last operaror in the filter selection.
     *
     * @return {String}
     *   The last operator or empty string if none (ex: empty selection).
     */
    getLastOperator() {
      const operators = this.filterSelection.querySelectorAll('[data-operator]');
      if (operators.length) {
        return operators[operators.length - 1].getAttribute('data-operator');
      }
      return '';
    }

    /**
     * Get the the operator options based on the given operator.
     *
     * @param {Boolean} advancedMode
     *   Wether we are in advanced mode or not.
     * @param {String} operator
     *   Current operator: `and` or `or`.
     *
     * @return {Object}
     *   List of operator options with the operator as key and true as key if
     *   it is available.
     */
    getOperatorOptions(operator) {
      const operators = {};
      if (!this.advancedMode) {
        if (operator === 'and') {
          operators['and'] = true;
        }
        else if (operator === 'or') {
          operators['or'] = true;
        }
        else {
          operators['all'] = true;
          operators['any'] = true;
        }
      }
      else {
        if (!operator) {
          operators['with'] = true;
          operators['without'] = true;
        }
        else {
          if (operator === 'and' || operator === 'or') {
            operators[operator] = true;
          }
          else {
            operators['and'] = true;
            operators['or'] = true;
          }
          operators['and-with'] = true;
          operators['and-without'] = true;
          operators['or-with'] = true;
          operators['or-without'] = true;
        }
      }
      return operators;
    }

    /** **** Operator Switcher **** **/

    /**
     * Toggle the visibility of an operator switcher.
     *
     * @param {Element} button
     *   Toggling button.
     * @param {Element} list
     *   List containing the operators.
     * @param {Boolean} expand
     *   Whether to show or hide the the switcher.
     */
    toggleOperatorSwitcher(button, list, expand) {
      if (expand === true) {
        if (button.getAttribute('aria-expanded') === 'false') {
          button.setAttribute('aria-expanded', true);
          list.setAttribute('data-hidden', false);
          list.focus();
        }
      }
      else {
        if (button.getAttribute('aria-expanded') === 'true') {
          list.setAttribute('data-hidden', true);
          button.setAttribute('aria-expanded', false);
          button.focus();
        }
      }
    }

    /**
     * Update the selected value of an operator switcher.
     *
     * @param {Element} element
     *   The operator switcher element.
     * @param {Element|String} selection
     *   The selected operator.
     * @param {String} previous
     *   The previous operator value
     */
    updateOperatorSwitcher(element, selection, previous) {
      if (typeof selection === 'string') {
        selection = element.querySelector('[data-option="' + selection + '"]');
      }

      const options = element.querySelectorAll('[data-option]');
      const selected = element.querySelector('[aria-selected]');
      const operator = selection.getAttribute('data-option');
      const button = element.querySelector('button');
      const list = element.querySelector('ul');

      // @todo check if we should also execute this code if previous is defined
      // but empty.
      if (typeof previous !== 'undefined') {
        const operators = this.getOperatorOptions(previous);

        // Disable/enable options of the operator switcher.
        for (const option of options) {
          if (operators[option.getAttribute('data-option')] === true) {
            option.removeAttribute('aria-disabled');
          }
          else {
            option.setAttribute('aria-disabled', true);
          }
        }
      }

      // Update the selected option in the operator switcher.
      if (selected !== selection) {
        element.setAttribute('data-operator', operator);
        if (selected) {
          selected.removeAttribute('aria-selected');
        }
        selection.setAttribute('aria-selected', true);
        list.setAttribute('aria-activedescendant', selection.id);
        // Update button label.
        const label = this.getText(selection);
        this.setText(button, label);
        button.setAttribute('aria-label', this.labels.switchOperator.replace('_operator_', label));
      }
    }

    /**
     * Make sure the operators in the filter selection are valid.
     */
    updateOperatorSwitchers() {
      const elements = this.filterSelection.querySelectorAll('[data-operator]');
      const defaults = this.defaultOperators;

      let previousOperator = '';
      let previousField = '';

      for (let i = 0, l = elements.length; i < l; i++) {
        const element = elements[i];
        const field = element.parentNode.getAttribute('data-field');
        const operator = element.getAttribute('data-operator');
        let replacement = operator;

        if (this.advancedMode) {
          if (i === 0 && operator !== 'with' && operator !== 'without') {
            replacement = operator.indexOf('without') > 0 ? 'without' : 'with';
          }
          else if (operator === 'and' && previousOperator === 'or') {
            replacement = 'and-with';
          }
          else if (operator === 'or' && previousOperator === 'and') {
            replacement = 'or-with';
          }
          else if (operator === 'any' || operator === 'all') {
            replacement = 'and-with';
          }

          this.updateOperatorSwitcher(element, replacement, previousOperator);
        }
        else {
          // First filter for the field.
          if (field !== previousField) {
            if (operator !== 'any' && operator !== 'all') {
              replacement = defaults[field] === 'and' ? 'all' : 'any';
              // We need to have a peek at the next element to see if there is
              // more than 1 selected value for the field. In that case we adjust
              // the operator based on the next filter's operator.
              if (i + 1 < l) {
                var nextElement = elements[i + 1];
                if (nextElement.parentNode.getAttribute('data-field') === field) {
                  replacement = nextElement.getAttribute('data-operator') === 'or' ? 'any' : 'all';
                }
              }
            }
          }
          else if (previousOperator === 'any' && operator !== 'or') {
            replacement = 'or';
          }
          else if (previousOperator === 'all' && operator !== 'and') {
            replacement = 'and';
          }
          // In the simplified mode, we don't adjust the switcher options based on
          // the previous operator but based on the current operator instead.
          if (replacement !== operator) {
            this.updateOperatorSwitcher(element, replacement, replacement);
          }
        }

        previousField = field;
        previousOperator = replacement;
      }

      // Update the operator selector with the allowed operators.
      this.updateOperatorSelector();

      // Update the filter selection count and hide/show the actions.
      this.updateFilterSelection();
    }


    /** ****** HELPERS ******* **/

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
    createElement(tag, attributes, content) {
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

    /**
     * Create a label DOM element.
     *
     * @param {String} target
     *   Form element this label is for.
     * @param {String} label
     *   Label text.
     * @param {Object} attributes
     *   Attributes for the label.
     *
     * @return Element
     *   The label DOM element.
     */
    createLabel(target, label, attributes) {
      attributes = attributes || {};
      attributes.for = target;
      return this.createElement('label', attributes, label);
    }

    /**
     * Create an option DOM element.
     *
     * @param {String} value
     *   The value of the option.
     * @param {String} label
     *   The label of the option.
     * @param {Object} attributes
     *   Attributes for the option.
     *
     * @return Element
     *   The option DOM element.
     */
    createOption(value, label, attributes) {
      attributes = attributes || {};
      attributes.value = value;
      return this.createElement('option', attributes, label);
    }

    /**
     * Create a button DOM element.
     *
     * @param {Object} attributes
     *   Attributes for the label.
     * @param {String|Element|Array} content
     *   The content of the button.
     *
     * @return Element
     *   The label DOM element.
     */
    createButton(attributes, content) {
      attributes = attributes || {};
      attributes.type = 'button';
      return this.createElement('button', attributes, content);
    }

    /**
     * Create a document fragment.
     *
     * @return {DocumentFragment}
     *   The document fragment.
     */
    createFragment() {
      const fragment = document.createDocumentFragment();
      // Passed arguments are assumed being DOM elements.
      for (var i = 0, l = arguments.length; i < l; i++) {
        fragment.appendChild(arguments[i]);
      }
      return fragment;
    }

    /**
     * Trigger an event on a DOM element.
     *
     * @param {Element} element
     *   DOM element.
     * @param {String} eventName
     *   Name of the event.
     */
    triggerEvent(element, eventName) {
      element.dispatchEvent(new Event(eventName));
    }

    /**
     * Set the text of a DOM element.
     *
     * @param {Element} element
     *   The DOM element.
     * @param {String} text
     *   The text.
     */
    setText(element, text) {
      if ('textContent' in element) {
        element.textContent = text;
      }
      else {
        element.innerText = text;
      }
    }

    /**
     * Get the text of a DOM element.
     *
     * @param {Element} element
     *   The DOM element.
     * @param {Boolean} trimmed
     *   Whether to trim the text or not.
     *
     * @return {String}
     *   The text of the element.
     */
    getText(element, trimmed) {
      var text = 'textContent' in element ? element.textContent : element.innerText;
      return trimmed !== false ? text.trim() : text;
    }

    /**
     * Create a date object.
     *
     * @param {String} date
     *   ISO 8601 date.
     * @param {Boolean} stripTime
     *   Strip time information.
     *
     * @return {Date}
     *   A date object.
     */
    createDate(date, stripTime = true) {
      if (stripTime) {
        date = date.replace(/(\d{4})(\d{2})(\d{2})/, '$1-$2-$3T00:00:00+00:00');
      }
      return SimpleDatePicker.date(date).utc();
    }

    /**
     * Format a date range filter label.
     *
     * @param {String} from
     *   The starting date.
     * @param {String} to
     *   The ending date.
     *
     * @return {String}
     *   The formatted date range label.
     */
    formatDateRangeLabel(from, to) {
      let label = '';
      let start = '';
      let end = '';
      if (from && to) {
        if (from === to) {
          label = this.labels.dates.on;
          start = this.createDate(from).format('YYYY/MM/DD');
        }
        else if (from > to) {
          label = this.labels.dates.range;
          start = this.createDate(to).format('YYYY/MM/DD');
          end = this.createDate(from).format('YYYY/MM/DD');
        }
        else {
          label = this.labels.dates.range;
          start = this.createDate(from).format('YYYY/MM/DD');
          end = this.createDate(to).format('YYYY/MM/DD');
        }
      }
      else if (from) {
        label = this.labels.dates.after;
        start = this.createDate(from).substract('day', 1).format('YYYY/MM/DD');
      }
      else if (to) {
        label = this.labels.dates.before;
        end = this.createDate(to).add('day', 1).format('YYYY/MM/DD');
      }
      return label ? label.replace('_start_', start).replace('_end_', end) : '';
    }

    /**
     * Format a date range filter value.
     *
     * @param {String} from
     *   The starting date.
     * @param {String} to
     *   The ending date.
     *
     * @return {String}
     *   The formatted date range value.
     */
    formatDateRangeValue(from, to) {
      if (from && to) {
        if (from === to) {
          return from;
        }
        else if (from > to) {
          return to + '-' + from;
        }
        else {
          return from + '-' + to;
        }
      }
      else if (from) {
        return from + '-';
      }
      else if (to) {
        return '-' + to;
      }
      return '';
    }

  }


  Drupal.behaviors.ochaAiChatPluginSourceReliefWeb = {
    attach: function (context, settings) {
      once('ocha-ai-chat-plugin-source-reliefweb', '[data-ocha-ai-chat-plugin-source-reliefweb]', context).forEach(element => {
        const settings = JSON.parse(element.getAttribute('data-ocha-ai-chat-plugin-source-reliefweb'));
        const handler = new Handler(element, settings);
        handler.initialize();
      });
    }
  };
})(Drupal);
