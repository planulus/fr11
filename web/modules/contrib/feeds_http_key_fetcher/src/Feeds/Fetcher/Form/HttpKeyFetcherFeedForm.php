<?php

namespace Drupal\feeds_http_key_fetcher\Feeds\Fetcher\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Feeds\Fetcher\Form\HttpFetcherFeedForm;

/**
 * Provides a form on the feed edit page for the HttpFetcher.
 */
class HttpKeyFetcherFeedForm extends HttpFetcherFeedForm {

    /**
     * {@inheritdoc}
     */
    public function defaultConfiguration()
    {
        $defaults = parent::defaultConfiguration();
    }


  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, ?FeedInterface $feed = NULL) {
      parent::buildConfigurationForm($form, $form_state, $feed);

      $form['source'] = [
          '#title' => $this->t('Feed URL'),
          '#type' => 'url',
          '#default_value' => $feed->getSource(),
          '#maxlength' => 2048,
          '#required' => TRUE,
      ];
      $form['key'] = [
          '#title' => $this->t('Authorization X API Key:'),
          '#description' => "The optional api key to provide for an <strong>x-api-key</strong> HTTP header.",
          '#type' => 'textfield',
          '#default_value' => $feed->getConfigurationFor($this->plugin)['key'],
          '#maxlength' => 255,
      ];

    return $form;
  }
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state, ?FeedInterface $feed = NULL)
    {
        parent::submitConfigurationForm($form, $form_state, $feed);
        $feed_config = $feed->getConfigurationFor($this->plugin);
        $feed_config['key'] = $form_state->getValue('key');
        $feed->setConfigurationFor($this->plugin, $feed_config);
    }
}
