<?php

declare(strict_types=1);

namespace Drupal\webprofiler\Render;

use Drupal\big_pipe\Render\BigPipe;
use Drupal\Core\Render\AttachmentsInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extends the Drupal core big_pipe service to trace placeholder expansion.
 *
 * BigPipe sends one embedded response per placeholder, and the profiler
 * needs to know which placeholder each belongs to, so it can collect them
 * as separate child profiles. Both hooks below wrap their parent, so the
 * placeholder sending logic itself stays in core.
 *
 * ::renderPlaceholder() marks the rendered elements with the placeholder
 * ID, and ::filterEmbeddedResponse() moves that mark onto a response
 * header just before the KernelEvents::RESPONSE event is dispatched, which
 * is when \Drupal\webprofiler\EventListener\ProfilerListener reads it.
 */
class TraceableBigPipe extends BigPipe {

  /**
   * Attachment key carrying the placeholder ID between the two hooks.
   */
  private const PLACEHOLDER_ATTACHMENT = 'webprofiler_big_pipe_placeholder';

  /**
   * Response header the profiler listener reads the placeholder ID from.
   */
  private const PLACEHOLDER_HEADER = 'X-Drupal-BigPipe-Placeholder';

  /**
   * {@inheritdoc}
   */
  protected function renderPlaceholder($placeholder, array $placeholder_render_array) {
    $elements = parent::renderPlaceholder($placeholder, $placeholder_render_array);
    $elements['#attached'][self::PLACEHOLDER_ATTACHMENT] = $placeholder;

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  protected function filterEmbeddedResponse(Request $fake_request, Response $embedded_response) {
    // Responses that are not a placeholder replacement, such as the one
    // carrying the bottom JavaScript, are never marked and pass through.
    if ($embedded_response instanceof AttachmentsInterface) {
      $attachments = $embedded_response->getAttachments();

      if (isset($attachments[self::PLACEHOLDER_ATTACHMENT])) {
        $placeholder = $attachments[self::PLACEHOLDER_ATTACHMENT];

        // The attachments processors reject any #attached key they do not know
        // about, so the mark has to go before the event is dispatched.
        unset($attachments[self::PLACEHOLDER_ATTACHMENT]);
        $embedded_response->setAttachments($attachments);

        $embedded_response->headers->set(self::PLACEHOLDER_HEADER, $placeholder);
      }
    }

    return parent::filterEmbeddedResponse($fake_request, $embedded_response);
  }

}
