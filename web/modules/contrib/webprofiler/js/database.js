/**
 * @file
 * Database panel app.
 */
((Drupal) => {
  Drupal.behaviors.webprofiler_database = {
    attach() {
      once('db', '.wp-db-query').forEach((element) => {
        // Swap placeholders.
        element
          .querySelector('.wp-executable-toggle')
          .addEventListener('click', () => {
            element
              .querySelector('.wp-query-placeholder')
              .classList.toggle('is-hidden');
            element
              .querySelector('.wp-query-executable')
              .classList.toggle('is-hidden');
          });

        // Copy to clipboard.
        if (navigator.clipboard && window.isSecureContext) {
          element
            .querySelector('.wp-query-copy')
            .addEventListener('click', () => {
              const query = element.querySelector(
                '.wp-query-executable',
              ).innerText;
              navigator.clipboard.writeText(query);
            });
        } else {
          element.querySelector('.wp-query-copy').classList.toggle('is-hidden');
        }
      });
    },
  };
})(Drupal);
