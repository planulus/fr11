<?php

declare(strict_types=1);

namespace Drupal\webprofiler\Render;

use Drupal\Core\Render\AttachmentsInterface;
use Drupal\Core\Render\AttachmentsResponseProcessorInterface;
use Drupal\webprofiler\DataCollector\AssetsDataCollector;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extends the Drupal core html_response.attachments_processor service.
 */
class HtmlResponseAttachmentsProcessor implements AttachmentsResponseProcessorInterface {

  public function __construct(
    private readonly AttachmentsResponseProcessorInterface $original,
    private readonly AssetsDataCollector $dataCollector,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function processAttachments(AttachmentsInterface $response): AttachmentsInterface|Response {
    $response = $this->original->processAttachments($response);

    // The decorated processor can return a response without attachments. The
    // core processor does this when an enforced response, usually a redirect
    // coming from a form submission, is raised while rendering placeholders.
    if (!$response instanceof AttachmentsInterface) {
      return $response;
    }

    $attachments = $response->getAttachments();

    $this->dataCollector->setLibraries($attachments['library'] ?? []);
    $this->dataCollector->setPlaceholders($attachments['big_pipe_placeholders'] ?? []);

    return $response;
  }

}
