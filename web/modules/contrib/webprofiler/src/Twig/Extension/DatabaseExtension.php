<?php

declare(strict_types=1);

namespace Drupal\webprofiler\Twig\Extension;

use Drupal\Core\Database\Database;
use Highlight\Highlighter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extensions to render database query information.
 */
class DatabaseExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('query_type', [$this, 'queryType']),
      new TwigFunction('query', [$this, 'query']),
      new TwigFunction('query_executable', [$this, 'queryExecutable']),
    ];
  }

  /**
   * Return the type of the query.
   *
   * @param string $query
   *   A SQL query.
   *
   * @return string
   *   The type of the query.
   */
  public function queryType(string $query): string {
    $parts = \explode(' ', $query);
    return \strtoupper($parts[0]);
  }

  /**
   * Return the query without new lines and double quotes.
   *
   * @param string $query
   *   A query.
   *
   * @return string
   *   The query without new lines and double quotes.
   */
  public function query(string $query): string {
    $code = \str_replace(
        search:  "\n",
        replace: ' ',
        subject: \str_replace(
          search:  '"',
          replace: '',
          subject: $query,
        ),
      );

    return $this->highlight($code);
  }

  /**
   * Return the executable version of the query.
   *
   * @param array $query
   *   A query array.
   *
   * @return string
   *   The executable version of the query.
   */
  public function queryExecutable(array $query): string {
    $conn = Database::getConnection();

    $quoted = [];

    if (isset($query['args'])) {
      foreach ((array) $query['args'] as $key => $val) {
        $quoted[$key] = \is_null($val) ? 'NULL' : $conn->quote($val);
      }
    }

    $code = \strtr(
      \str_replace(
        search:  "\n",
        replace: ' ',
        subject: \str_replace(
          search:  '"',
          replace: '',
          subject: $query['query'],
        ),
      ),
      $quoted);

    return $this->highlight($code);
  }

  /**
   * Highlight the SQL code.
   *
   * @param string $code
   *   The SQL code.
   *
   * @return string
   *   The highlighted SQL code.
   */
  private function highlight(string $code): string {
    $hl = new Highlighter();
    try {
      $highlighted = $hl->highlight('sql', $code);

      return $highlighted->value;
    }
    catch (\Exception $e) {
      return $code;
    }
  }

}
