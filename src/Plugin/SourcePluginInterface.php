<?php

namespace Drupal\ocha_ai_chat\Plugin;

use Drupal\Core\Form\FormStateInterface;

/**
 * Interface for the Source plugins.
 */
interface SourcePluginInterface {

  /**
   * Get the form widget to enter the source information.
   *
   * Note: this should add the widget as `$form['source']`.
   *
   * @param array $form
   *   The main form to which add the source widget.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $defaults
   *   The main form default settings.
   *
   * @return array
   *   The form with the added widget.
   */
  public function getSourceWidget(array $form, FormStateInterface $form_state, array $defaults): array;

  /**
   * Get the source data from the form state.
   *
   * @param array $form
   *   The main form to which add the source widget.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The source data.
   */
  public function getSourceData(array $form, FormStateInterface $form_state): array;

  /**
   * Redner the source data.
   *
   * @param array $data
   *   The source data to render.
   *
   * @return array
   *   Render array,
   */
  public function renderSourceData(array $data): array;

  /**
   * Generate Sources for the given text.
   *
   * @param array $data
   *   The source data used to retrieve the source documents. Ex: API URL.
   * @param int $limit
   *   Maximum number of documents to retrieve.
   *
   * @return array
   *   Associative array with the resource as key and associative arrays of
   *   documents with their IDs as keys and with id, title, url,
   *   source and contents (associative array with type, title, url and optional
   *   content property dependending on the type) as values.
   */
  public function getDocuments(array $data, int $limit = 10): array;

}
