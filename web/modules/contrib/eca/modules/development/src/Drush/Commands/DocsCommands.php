<?php

namespace Drupal\eca_development\Drush\Commands;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Action\ActionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\RendererInterface;
use Drupal\eca\Entity\Eca;
use Drupal\eca\Plugin\ECA\Condition\ConditionInterface;
use Drupal\eca\Plugin\ECA\Event\EventInterface;
use Drupal\eca\Service\Actions;
use Drupal\eca\Service\Conditions;
use Drupal\eca\Service\Events;
use Drupal\modeler_api\Api;
use Drupal\modeler_api\ExportRecipe;
use Drupal\modeler_api\Plugin\ModelerApiModelOwner\ModelOwnerInterface;
use Drupal\modeler_api\Plugin\ModelOwnerPluginManager;
use Drush\Attributes\Command;
use Drush\Attributes\Usage;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader as TwigLoader;

/**
 * ECA documentation Drush command file.
 */
final class DocsCommands extends DrushCommands {

  use AutowireTrait;

  public const string NAMESPACE = 'drupal-eca-recipe';

  /**
   * Table of contents.
   *
   * @var array
   */
  protected array $toc = [];

  /**
   * List of all processed modules.
   *
   * @var array
   */
  protected array $modules = [];

  /**
   * List of extensions.
   *
   * @var \Drupal\Core\Extension\Extension[]
   */
  protected ?array $moduleExtensions;

  /**
   * Twig array loader.
   *
   * @var \Twig\Loader\ArrayLoader
   */
  protected TwigLoader $twigLoader;

  /**
   * Twig environment service.
   *
   * @var \Twig\Environment
   */
  protected TwigEnvironment $twigEnvironment;

  /**
   * The model owner plugin manager.
   *
   * @var \Drupal\modeler_api\Plugin\ModelerApiModelOwner\ModelOwnerInterface
   */
  private ModelOwnerInterface $owner;

  /**
   * DocsCommands constructor.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Actions $actionServices,
    #[Autowire(service: 'eca.service.condition')]
    protected Conditions $conditionServices,
    #[Autowire(service: 'eca.service.event')]
    protected Events $eventsServices,
    protected FileSystemInterface $fileSystem,
    protected ModuleHandlerInterface $moduleHandler,
    protected ModuleExtensionList $moduleExtensionList,
    protected Api $modelerApi,
    #[Autowire(service: 'modeler_api.export.recipe')]
    protected ExportRecipe $exportRecipe,
    #[Autowire(service: 'plugin.manager.modeler_api.model_owner')]
    private readonly ModelOwnerPluginManager $modelOwnerPluginManager,
    protected RendererInterface $renderer,
    #[Autowire(service: 'twig')]
    protected TwigEnvironment $twig,
  ) {
    parent::__construct();
    $this->twigLoader = new TwigLoader();
    $this->twigEnvironment = new TwigEnvironment($this->twigLoader);
    $this->moduleExtensions = $moduleExtensionList->getList();
    $owner = $this->modelOwnerPluginManager->createInstance('eca');
    if ($owner instanceof ModelOwnerInterface) {
      $this->owner = $owner;
    }
  }

  /**
   * Export documentation for all plugins.
   */
  #[Command(name: 'eca:doc:plugins', aliases: [])]
  #[Usage(name: 'eca:doc:plugins', description: 'Export documentation for all plugins.')]
  public function plugins(): void {
    @$this->fileSystem->mkdir('../mkdocs/include/modules', NULL, TRUE);
    @$this->fileSystem->mkdir('../mkdocs/include/plugins', NULL, TRUE);
    $this->toc['0-ECA']['0-placeholder'] = 'plugins/eca/index.md';

    foreach ($this->eventsServices->events() as $event) {
      $this->pluginDoc($event);
    }
    foreach ($this->conditionServices->conditions() as $condition) {
      $this->pluginDoc($condition);
    }
    foreach ($this->actionServices->actions() as $action) {
      $this->pluginDoc($action);
    }
    $this->updateToc('plugins');
  }

  /**
   * Export documentation for all models.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  #[Command(name: 'eca:doc:models', aliases: [])]
  #[Usage(name: 'eca:doc:models', description: 'Export documentation for all models.')]
  public function models(): void {
    /** @var \Drupal\eca\Entity\Eca $eca */
    foreach ($this->entityTypeManager
      ->getStorage('eca')
      ->loadMultiple() as $eca) {
      $this->modelDoc($eca);
      $owner = $this->modelerApi->findOwner($eca);
      $this->exportRecipe->doExport($owner, $eca, $this->exportRecipe->defaultName($eca), self::NAMESPACE, '../recipes/' . $eca->id());
    }
    $this->updateToc('library');
  }

  /**
   * Update the TOC file identified by $key.
   *
   * The freshly generated TOC is merged append-only with any TOC that already
   * exists on disk: existing entries that are missing from the new TOC are
   * preserved, while freshly generated values always win on key conflicts.
   * This guarantees that previously documented plugins or models are never
   * dropped from the navigation, even when they can no longer be discovered
   * during the current run.
   *
   * @param string $key
   *   The key for the TOC to update.
   */
  private function updateToc(string $key): void {
    $filename = '../mkdocs/toc/' . $key . '.yml';

    // Merge with a potentially existing TOC on disk, append-only. The existing
    // navigation list is converted back into the internal weighted assoc-map
    // shape and merged into the freshly generated TOC so that the existing
    // transform below produces the exact same output format as before.
    if (is_file($filename)) {
      $existingContent = file_get_contents($filename);
      if ($existingContent !== '') {
        $existingNav = Yaml::decode($existingContent);
        if (is_array($existingNav)) {
          $existingToc = $this->navToToc($existingNav, TRUE);
          $this->mergeTocAppendOnly($this->toc, $existingToc);
        }
      }
    }

    $this->sortNestedArrayAssoc($this->toc);
    $content = Yaml::encode($this->toc);
    $content = '- ' . $key . '/index.md' . PHP_EOL . str_replace(
      ['0-ECA:', '  0-placeholder: ', '  1-', '  2-', '  3-'],
      ['ECA:', '  ', '  ', '  ', '  '],
      $content);
    $content = preg_replace_callback('/\n\s*/', static function (array $matches) {
      return $matches[0] . '- ';
    }, $content);
    file_put_contents($filename, substr($content, 0, -2));
  }

  /**
   * Converts a navigation list back into the internal weighted assoc-map.
   *
   * This is the inverse of the transform applied in ::updateToc(): it takes a
   * decoded mkdocs navigation list and rebuilds the weighted, associative TOC
   * structure held in $this->toc, so that an existing on-disk TOC can be
   * merged with a freshly generated one.
   *
   * @param array $nav
   *   The decoded navigation list.
   * @param bool $top
   *   Whether $nav is the top level of the navigation list. At the top level
   *   the synthetic leading "{key}/index.md" link is skipped and the "ECA"
   *   section is mapped back to its weighted "0-ECA" key.
   *
   * @return array
   *   The navigation list expressed as a weighted assoc-map.
   */
  private function navToToc(array $nav, bool $top): array {
    $out = [];
    foreach ($nav as $item) {
      if (!is_array($item)) {
        // Scalar string entries are index links. At the top level this is the
        // synthetic leading "{key}/index.md" link prepended in ::updateToc(),
        // which must not be added back. Otherwise it is a provider index link.
        if (!$top) {
          $out['0-placeholder'] = $item;
        }
        continue;
      }
      $navKey = array_key_first($item);
      $val = $item[$navKey];
      $weightedKey = match ($navKey) {
        'Events' => '1-Events',
        'Conditions' => '2-Conditions',
        'Actions' => '3-Actions',
        'ECA' => $top ? '0-ECA' : $navKey,
        default => $navKey,
      };
      $out[$weightedKey] = is_array($val) ? $this->navToToc($val, FALSE) : $val;
    }
    return $out;
  }

  /**
   * Deep-merges a source TOC into a target TOC, append-only.
   *
   * Only entries that are missing from $target are added from $source. When a
   * key exists in both and both values are arrays, the merge recurses. When a
   * key exists in both but the values are not both arrays (leaf links or a
   * type mismatch), the existing $target value is kept, so freshly generated
   * values always win on conflict.
   *
   * @param array $target
   *   The freshly generated TOC to merge into. Modified by reference.
   * @param array $source
   *   The existing TOC to merge from.
   */
  private function mergeTocAppendOnly(array &$target, array $source): void {
    foreach ($source as $key => $value) {
      if (!array_key_exists($key, $target)) {
        $target[$key] = $value;
      }
      elseif (is_array($target[$key]) && is_array($value)) {
        $this->mergeTocAppendOnly($target[$key], $value);
      }
    }
  }

  /**
   * Sort array by key recursively.
   *
   * @param mixed $a
   *   The array to sort by key.
   */
  private function sortNestedArrayAssoc(mixed &$a): void {
    if (!is_array($a)) {
      return;
    }
    ksort($a);
    foreach ($a as $k => $v) {
      $this->sortNestedArrayAssoc($a[$k]);
    }
  }

  /**
   * Prepare documentation for given plugin.
   *
   * @param \Drupal\Component\Plugin\PluginInspectionInterface $plugin
   *   The ECA plugin for which documentation should be created.
   */
  private function pluginDoc(PluginInspectionInterface $plugin): void {
    if (!empty($plugin->getPluginDefinition()['nodocs']) || !empty($plugin->getPluginDefinition()['no_docs'])) {
      return;
    }
    $values = $this->getPluginValues($plugin);
    if ($values === NULL) {
      return;
    }
    $id = str_replace(':', '_', $plugin->getPluginId());
    $values['id_fs'] = $id;
    $this->modules[$values['provider']] = $values;

    $provider = $values['provider'];
    $values['extension_info'] = [
      'standalone' => TRUE,
      'module' => $provider,
    ];
    if (isset($this->moduleExtensions[$provider])) {
      // @phpstan-ignore-next-line
      if ($this->moduleExtensions[$provider]->origin === 'core') {
        $values['extension_info']['standalone'] = FALSE;
        $values['extension_info']['module'] = 'core';
      }
      else {
        // @phpstan-ignore-next-line
        $subpath = $this->moduleExtensions[$provider]->subpath;
        // @phpstan-ignore-next-line
        foreach ($this->moduleExtensions[$provider]->requires as $require) {
          // @phpstan-ignore-next-line
          if (isset($this->moduleExtensions[$require->getName()]) && str_contains($subpath, $this->moduleExtensions[$require->getName()]->subpath . '/')) {
            $values['extension_info']['standalone'] = FALSE;
            $values['extension_info']['module'] = $require->getName();
            break;
          }
        }
      }
    }

    $path = $values['path'];
    $filename = $path . '/' . $id . '.md';
    @$this->fileSystem->mkdir('../mkdocs/docs/' . $path, NULL, TRUE);
    file_put_contents('../mkdocs/docs/' . $filename, $this->render(__DIR__ . '/../../../templates/docs/plugin.md.twig', $values));

    $path = '../mkdocs/include/plugins/' . $values['provider'] . '/' . $values['type'] . '/';
    @$this->fileSystem->mkdir($path, NULL, TRUE);
    if (!file_exists($path . $id . '.md')) {
      file_put_contents($path . $id . '.md', '');
    }
    $path .= $id . '/';
    @$this->fileSystem->mkdir($path, NULL, TRUE);
    foreach ($values['fields'] as $field) {
      if (!file_exists($path . $field['name'] . '.md')) {
        file_put_contents($path . $field['name'] . '.md', '');
      }
    }

    if (!isset($values['toc'][$values['provider_name']])) {
      // Initialize TOC for a new provider.
      $values['toc'][$values['provider_name']]['0-placeholder'] = $values['provider_path'] . '/index.md';

      file_put_contents('../mkdocs/docs/' . $values['provider_path'] . '/index.md', $this->render(__DIR__ . '/../../../templates/docs/provider.md.twig', $values));
      if (!file_exists('../mkdocs/include/modules/' . $provider . '.md')) {
        file_put_contents('../mkdocs/include/modules/' . $provider . '.md', '');
      }
    }
    $values['toc'][$values['provider_name']][$values['weight'] . '-' . ucfirst($values['type']) . 's'][(string) $values['label']] = $filename;
  }

  /**
   * Extracts all required values from the given plugin.
   *
   * @param \Drupal\Component\Plugin\PluginInspectionInterface $plugin
   *   The ECA plugin for which values should be extracted.
   *
   * @return array|null
   *   The extracted values.
   */
  private function getPluginValues(PluginInspectionInterface $plugin): ?array {
    $values = $plugin->getPluginDefinition();
    if ($values['provider'] === 'core') {
      $values['provider_name'] = 'Drupal core';
    }
    else {
      $values['provider_name'] = $this->moduleExtensionList->getName($values['provider']);
    }
    if (str_starts_with($values['provider'], 'eca_')) {
      $basePath = str_replace('eca_', 'eca/', $values['provider']);
      $values['toc'] = &$this->toc['0-ECA'];
    }
    else {
      $basePath = $values['provider'];
      $values['toc'] = &$this->toc;
    }
    if (!isset($values['version_introduced'])) {
      $values['version_introduced'] = 'unknown';
    }
    $form_state = new FormState();
    if ($plugin instanceof EventInterface) {
      $weight = 1;
      $type = 'event';
      $form = $plugin->buildConfigurationForm([], $form_state);
      $values['tokens'] = $plugin->getTokens();
    }
    elseif ($plugin instanceof ConditionInterface) {
      $weight = 2;
      $type = 'condition';
      $form = $plugin->buildConfigurationForm([], $form_state);
    }
    elseif ($plugin instanceof ActionInterface) {
      $weight = 3;
      $type = 'action';
      $form = $this->actionServices->getConfigurationForm($plugin, $form_state);
      if ($form === NULL) {
        return NULL;
      }
    }
    else {
      $weight = 4;
      $type = 'error';
      $form = [];
    }
    $values['path'] = sprintf('plugins/%s/%ss',
      $basePath,
      $type
    );
    $values['provider_path'] = sprintf('plugins/%s',
      $basePath,
    );
    $fields = [];
    $extraDescriptions = [];
    foreach ($form as $key => $def) {
      if (empty($def)) {
        continue;
      }
      switch ($def['#type'] ?? 'markup') {
        case 'hidden':
        case 'actions':
          continue 2;

        case 'item':
        case 'markup':
        case 'container':
          if (isset($def['#markup']) && !str_starts_with($key, 'eca_token_')) {
            $extraDescriptions[] = $this->toMarkupString($def['#markup']);
          }
          continue 2;

        default:
          $fields[] = [
            'name' => $key,
            'label' => $this->toMarkupString($def['#title'] ?? $key),
            'description' => $this->toMarkupString($def['#description'] ?? ''),
          ];
      }
    }
    $values['weight'] = $weight;
    $values['type'] = $type;
    $values['fields'] = $fields;
    $values['extraDescriptions'] = $extraDescriptions;
    return $values;
  }

  /**
   * Safely converts a form property value into a plain string.
   *
   * Form properties such as #title, #description and #markup may be a string,
   * a stringable object (e.g. TranslatableMarkup) or a full render array. When
   * a render array is echoed directly by Twig, PHP raises an "Array to string
   * conversion" warning, so render arrays are rendered to a string first.
   *
   * @param mixed $value
   *   The raw form property value.
   *
   * @return string
   *   The value as a plain string. NULL becomes an empty string.
   */
  private function toMarkupString(mixed $value): string {
    if ($value === NULL) {
      return '';
    }
    if (is_array($value)) {
      // Temporarily disable Twig debug so the rendered HTML is not polluted
      // with THEME DEBUG comments on dev sites where twig.config.debug is on.
      // The core "twig" service is the same environment used during theme
      // rendering, and isDebug() is read live at render time. Restore the
      // original state afterwards, even if rendering throws.
      $debug = $this->twig->isDebug();
      if ($debug) {
        $this->twig->disableDebug();
      }
      try {
        return (string) $this->renderer->renderInIsolation($value);
      }
      finally {
        if ($debug) {
          $this->twig->enableDebug();
        }
      }
    }
    return (string) $value;
  }

  /**
   * Creates documentation for the given ECA model.
   *
   * @param \Drupal\eca\Entity\Eca $eca
   *   The ECA config entity for which documentation should be created.
   */
  private function modelDoc(Eca $eca): void {
    $modeler = $this->modelerApi->findOwner($eca);
    if ($modeler === NULL) {
      return;
    }
    $tags = $modeler->getTags($eca);
    if (empty($tags) || (count($tags) === 1 && ($tags[0] === 'untagged' || $tags[0] === ''))) {
      // Do not export models without at least one tag.
      return;
    }

    $values = [
      'rawid' => $eca->id(),
      'id' => str_replace([':', ' '], '_', mb_strtolower($eca->label())),
      'label' => $eca->label(),
      'version' => $eca->get('version'),
      'changelog' => $modeler->getChangelog($eca),
      'main_tag' => $tags[0],
      'tags' => $tags,
      'documentation' => $modeler->getDocumentation($eca),
      'events' => [],
      'conditions' => [],
      'actions' => [],
      'model_filename' => $modeler->getPluginId() . '-' . $eca->id(),
      'library_path' => 'library/' . $tags[0],
      'namespace' => self::NAMESPACE,
    ];
    foreach ($eca->getUsedEvents() as $event) {
      $label = $eca->getEventInfo($event);
      $plugin = $event->getPlugin();
      if (!empty($plugin->getPluginDefinition()['nodocs'])) {
        continue;
      }
      $info = $this->getPluginValues($plugin);
      $id = str_replace(':', '_', $plugin->getPluginId());
      $values['events'][] = '[' . $label . '](/' . $info['path'] . '/' . $id . '.md)';
    }
    $values['events'] = array_unique($values['events']);
    foreach ($eca->getConditions() as $condition) {
      if ($plugin = $this->conditionServices->createInstance($condition['plugin'])) {
        if (!empty($plugin->getPluginDefinition()['nodocs'])) {
          continue;
        }
        $label = $condition['label'] ?? $plugin->getPluginDefinition()['label'];
        $info = $this->getPluginValues($plugin);
        $id = str_replace(':', '_', $plugin->getPluginId());
        $values['conditions'][] = '[' . $label . '](/' . $info['path'] . '/' . $id . '.md)';
      }
    }
    $values['conditions'] = array_unique($values['conditions']);
    foreach ($eca->getActions() as $action) {
      if ($plugin = $this->actionServices->createInstance($action['plugin'])) {
        if (!empty($plugin->getPluginDefinition()['nodocs'])) {
          continue;
        }
        $label = $action['label'] ?? $plugin->getPluginDefinition()['label'];
        $info = $this->getPluginValues($plugin);
        $id = str_replace(':', '_', $plugin->getPluginId());
        $values['actions'][] = '[' . $label . '](/' . $info['path'] . '/' . $id . '.md)';
      }
    }
    $values['actions'] = array_unique($values['actions']);

    @$this->fileSystem->mkdir('../mkdocs/docs/' . $values['library_path'] . '/' . $values['id'], NULL, TRUE);

    $archiveFileName = '../mkdocs/docs/' . $values['library_path'] . '/' . $values['id'] . '/' . $values['model_filename'] . '.tar.gz';
    $values['dependencies'] = $this->modelerApi->exportArchive($this->owner, $eca);

    file_put_contents('../mkdocs/docs/' . $values['library_path'] . '/' . $values['id'] . '.md', $this->render(__DIR__ . '/../../../templates/docs/library.md.twig', $values));
    file_put_contents('../mkdocs/docs/' . $values['library_path'] . '/' . $values['id'] . '/' . $values['model_filename'] . '.xml', $modeler->getModeldata($eca));

    $this->toc[$values['main_tag']][$values['label']] = $values['library_path'] . '/' . $values['id'] . '.md';
  }

  /**
   * Renders a twig template in filename with given values.
   *
   * @param string $filename
   *   The filename of a twig template.
   * @param array $values
   *   The values for rendering.
   *
   * @return string
   *   The rendered result of the twig template.
   */
  private function render(string $filename, array $values): string {
    $this->twigLoader->setTemplate($filename, file_get_contents($filename));
    try {
      return $this->twigEnvironment->render($filename, $values);
    }
    catch (LoaderError | RuntimeError | SyntaxError) {
      // @todo Log these exceptions.
    }
    return '';
  }

}
