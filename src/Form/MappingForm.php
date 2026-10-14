<?php

namespace Drupal\ash_salesforce\Form;

use Drupal\ash_salesforce\Entity\Mapping;
use Drupal\ash_salesforce\Salesforce\Field;
use Drupal\ash_salesforce\Salesforce\ParsedForm;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Maps a site form onto a Salesforce form, one site form field at a time.
 *
 * Each step shows one site form field and the Salesforce fields still free;
 * picking one is a single click, and the likeliest is offered first. Then
 * fixed values (record type, origin), then a summary to review and save.
 */
class MappingForm extends EntityForm {

  /**
   * The wrapper AJAX steps replace.
   */
  protected const WRAPPER = 'ash-salesforce-wizard';

  /**
   * The site form sources.
   *
   * @var \Drupal\ash_salesforce\Source\SourceManager
   */
  protected $sources;

  /**
   * The Salesforce form parser.
   *
   * @var \Drupal\ash_salesforce\Salesforce\FormParser
   */
  protected $parser;

  /**
   * The target guesser.
   *
   * @var \Drupal\ash_salesforce\Guesser
   */
  protected $guesser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = parent::create($container);
    $form->sources = $container->get('ash_salesforce.sources');
    $form->parser = $container->get('ash_salesforce.form_parser');
    $form->guesser = $container->get('ash_salesforce.guesser');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $form['#prefix'] = '<div id="' . self::WRAPPER . '">';
    $form['#suffix'] = '</div>';
    $form['#attached']['library'][] = 'ash_salesforce/wizard';
    $form['#attributes']['class'][] = 'ash-sf-wizard';

    $step = $form_state->get('step') ?? ($this->entity->isNew() ? 'setup' : 'summary');
    $form_state->set('step', $step);
    if ($step !== 'setup' && !$this->parsed()) {
      $this->messenger()->addError($this->t('The Salesforce form this mapping posts to is missing or unreadable.'));
      $step = 'setup';
      $form_state->set('step', $step);
    }

    return match ($step) {
      'setup' => $this->setupStep($form, $form_state),
      'field' => $this->fieldStep($form, $form_state),
      'constants' => $this->constantsStep($form, $form_state),
      default => $this->summaryStep($form, $form_state),
    };
  }

  /**
   * Names the mapping and picks the two forms.
   */
  protected function setupStep(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\ash_salesforce\Entity\Mapping $mapping */
    $mapping = $this->entity;
    $salesforce_forms = array_map(fn($f) => $f->label(), $this->entityTypeManager->getStorage('ash_salesforce_form')->loadMultiple());
    if (!$salesforce_forms) {
      $form['empty'] = [
        '#markup' => $this->t('Add the form Salesforce generated first, under <a href=":url">Salesforce forms</a>.', [
          ':url' => $this->entityTypeManager->getDefinition('ash_salesforce_form')->getLinkTemplate('add-form'),
        ]),
      ];
      return $form;
    }

    $site_forms = [];
    foreach ($this->sources->all() as $source) {
      foreach ($source->forms() as $bundle => $label) {
        $site_forms[$source->label()][$source->id() . ':' . $bundle] = $label;
      }
    }

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $mapping->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $mapping->id(),
      '#machine_name' => ['exists' => [Mapping::class, 'load']],
      '#disabled' => !$mapping->isNew(),
    ];
    $form['source'] = [
      '#type' => 'select',
      '#title' => $this->t('Site form'),
      '#options' => $site_forms,
      '#default_value' => implode(':', array_filter($mapping->getSource())) ?: NULL,
      '#required' => TRUE,
    ];
    $form['salesforce_form'] = [
      '#type' => 'select',
      '#title' => $this->t('Salesforce form'),
      '#description' => $this->t('Pick the sandbox form while testing; each environment can point at its own in settings.php.'),
      '#options' => $salesforce_forms,
      '#default_value' => $mapping->getSalesforceForm() ?: (count($salesforce_forms) === 1 ? array_key_first($salesforce_forms) : NULL),
      '#required' => TRUE,
    ];
    $form['next'] = $this->button($this->t('Start mapping'), 'start', '::start', NULL) + ['#button_type' => 'primary'];
    return $form;
  }

  /**
   * One site form field and the Salesforce fields it could go to.
   */
  protected function fieldStep(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\ash_salesforce\Entity\Mapping $mapping */
    $mapping = $this->entity;
    $elements = $this->elements();
    $keys = array_keys($elements);
    $index = min((int) $form_state->get('index'), count($keys) - 1);
    $key = $keys[$index];
    $element = $elements[$key];
    $current = $mapping->getFields()[$key] ?? NULL;

    $taken = array_diff_key(array_flip(array_diff($mapping->getFields(), [Mapping::NONE])), [$current => TRUE]);
    $free = array_diff_key($this->parsed()->fields, $taken);
    $first = $current && isset($free[$current]) ? $current : $this->guesser->guess($key, $element['label'], $free);
    if ($first) {
      $free = [$first => $free[$first]] + $free;
    }

    $form['progress'] = [
      '#markup' => '<p class="ash-sf-progress">' . $this->t('Field @n of @total', [
        '@n' => $index + 1,
        '@total' => count($keys),
      ]) . '</p>',
    ];
    $form['element'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ash-sf-element']],
      'label' => ['#markup' => '<strong>' . Html::escape($element['label']) . '</strong>'],
      'name' => ['#markup' => ' <code>' . Html::escape($key) . '</code>'],
      'prompt' => ['#markup' => '<p>' . $this->t('Where should this go in Salesforce?') . '</p>'],
    ];

    $form['targets'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ash-sf-targets']],
    ];
    foreach ($free as $target_key => $field) {
      $flag = match (TRUE) {
        $target_key === $current => $this->t('Current'),
        $target_key === $first => $this->t('Suggested'),
        default => NULL,
      };
      $form['targets'][$target_key] = [
        '#type' => 'container',
        '#attributes' => ['class' => array_filter(['ash-sf-target', $flag ? 'is-suggested' : NULL])],
        'button' => $this->button($field->label, 'target__' . $target_key, '::pick', $target_key) + ['#ash_element' => $key],
        'meta' => [
          '#markup' => '<span class="ash-sf-meta">' . ($flag ? '<em>' . $flag . '</em> · ' : '') . $this->meta($field) . '</span>',
        ],
      ];
    }
    $form['targets'][Mapping::NONE] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ash-sf-target', 'is-none']],
      'button' => $this->button($this->t("Don't send"), 'target__' . Mapping::NONE, '::pick', Mapping::NONE) + ['#ash_element' => $key],
      'meta' => ['#markup' => '<span class="ash-sf-meta">' . $this->t('Keep this field on the site only') . '</span>'],
    ];

    $form['nav'] = ['#type' => 'actions'];
    $form['nav']['back'] = $this->button($this->t('Back'), 'back', '::back', NULL);
    return $form;
  }

  /**
   * Fixed values for Salesforce fields no site form field fills.
   */
  protected function constantsStep(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\ash_salesforce\Entity\Mapping $mapping */
    $mapping = $this->entity;
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Values sent with every submission, such as the record type that routes it or an origin that says which site it came from. Leave the rest empty.') . '</p>',
    ];
    $form['constants'] = ['#tree' => TRUE];
    foreach ($this->constantTargets() as $key => $field) {
      $default = $mapping->getConstants()[$key] ?? '';
      $form['constants'][$key] = $field->options ? [
        '#type' => 'radios',
        '#title' => $field->label,
        '#options' => ['' => $this->t('Not set')] + array_combine($field->options, $field->options),
        '#default_value' => $default,
      ] : [
        '#type' => 'textfield',
        '#title' => $field->label,
        '#default_value' => $default,
        '#maxlength' => $field->maxlength ?? 255,
        '#size' => 40,
      ];
      $form['constants'][$key]['#description'] = $this->meta($field);
    }
    $form['nav'] = ['#type' => 'actions'];
    $form['nav']['back'] = $this->button($this->t('Back'), 'back', '::back', NULL);
    $form['nav']['next'] = ['#button_type' => 'primary'] + $this->button($this->t('Continue'), 'constants_next', '::saveConstants', NULL);
    return $form;
  }

  /**
   * Everything at a glance, with a way back to any one choice.
   */
  protected function summaryStep(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\ash_salesforce\Entity\Mapping $mapping */
    $mapping = $this->entity;
    $parsed = $this->parsed();
    $fields = $mapping->getFields();

    $form['fields'] = [
      '#type' => 'table',
      '#caption' => $this->t('Site form fields'),
      '#header' => [$this->t('Site form field'), $this->t('Salesforce field'), ''],
    ];
    foreach (array_keys($this->elements()) as $index => $key) {
      $target = $fields[$key] ?? NULL;
      $field = $target && $target !== Mapping::NONE ? $parsed->field($target) : NULL;
      $described = match (TRUE) {
        $target === NULL => '<strong>' . $this->t('Not decided') . '</strong>',
        $target === Mapping::NONE => $this->t('Not sent'),
        $field === NULL => '<strong>' . $this->t('Missing from this Salesforce form: @key', ['@key' => $target]) . '</strong>',
        default => Html::escape($field->label) . ' <code>' . Html::escape($field->name) . '</code>',
      };
      $form['fields'][$key] = [
        'element' => ['#markup' => Html::escape($this->elements()[$key]['label']) . ' <code>' . Html::escape($key) . '</code>'],
        'target' => ['#markup' => $described],
        'change' => $this->button($this->t('Change'), 'change__' . $key, '::change', NULL) + ['#ash_index' => $index],
      ];
    }

    $rows = [];
    foreach ($mapping->getConstants() as $key => $value) {
      $rows[] = [$parsed->field($key)?->label ?? $key, $value];
    }
    $form['constants'] = [
      '#type' => 'table',
      '#caption' => $this->t('Fixed values'),
      '#header' => [$this->t('Salesforce field'), $this->t('Value')],
      '#rows' => $rows,
      '#empty' => $this->t('None.'),
    ];
    $form['edit'] = ['#type' => 'actions'];
    $form['edit']['constants'] = $this->button($this->t('Change fixed values'), 'change_constants', '::changeConstants', NULL);
    $form['edit']['setup'] = $this->button($this->t('Change forms'), 'change_setup', '::changeSetup', NULL);

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send submissions to Salesforce'),
      '#default_value' => $mapping->status(),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    return $form_state->get('step') === 'summary' ? parent::actions($form, $form_state) : [];
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    // Steps write to the entity as they go; only the summary's toggle is left.
    if ($form_state->hasValue('status')) {
      $entity->setStatus((bool) $form_state->getValue('status'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

  /**
   * Submit handler: stores the setup choices and starts on the fields.
   */
  public function start(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\ash_salesforce\Entity\Mapping $mapping */
    $mapping = $this->entity;
    foreach (['label', 'source', 'salesforce_form'] as $property) {
      $mapping->set($property, $form_state->getValue($property));
    }
    if ($mapping->isNew()) {
      $mapping->set('id', $form_state->getValue('id'));
    }
    // Drop choices for fields the site form no longer has.
    $mapping->set('fields', array_intersect_key($mapping->getFields(), $this->elements()));

    $undecided = array_search(FALSE, array_map(fn($key) => isset($mapping->getFields()[$key]), array_keys($this->elements())), TRUE);
    if (!$this->elements()) {
      $this->go($form_state, 'constants');
    }
    elseif ($undecided === FALSE && !$mapping->isNew()) {
      $this->go($form_state, 'summary');
    }
    else {
      $this->go($form_state, 'field', $undecided === FALSE ? 0 : $undecided);
    }
  }

  /**
   * Submit handler: a Salesforce field (or none) was picked.
   */
  public function pick(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $this->entity->setField($trigger['#ash_element'], $trigger['#ash_target']);
    $next = (int) $form_state->get('index') + 1;
    if ($form_state->get('returning')) {
      $this->go($form_state, 'summary');
    }
    elseif ($next < count($this->elements())) {
      $this->go($form_state, 'field', $next);
    }
    else {
      $this->go($form_state, 'constants');
    }
  }

  /**
   * Submit handler: one step back.
   */
  public function back(array &$form, FormStateInterface $form_state): void {
    $index = (int) $form_state->get('index');
    match (TRUE) {
      (bool) $form_state->get('returning') => $this->go($form_state, 'summary'),
      $form_state->get('step') === 'constants' && $this->elements() => $this->go($form_state, 'field', count($this->elements()) - 1),
      $form_state->get('step') === 'field' && $index > 0 => $this->go($form_state, 'field', $index - 1),
      default => $this->go($form_state, 'setup'),
    };
  }

  /**
   * Submit handler: stores the fixed values.
   */
  public function saveConstants(array &$form, FormStateInterface $form_state): void {
    $this->entity->setConstants(array_map('strval', $form_state->getValue('constants') ?? []));
    $this->go($form_state, 'summary');
  }

  /**
   * Submit handler: revisits one site form field from the summary.
   */
  public function change(array &$form, FormStateInterface $form_state): void {
    $this->go($form_state, 'field', $form_state->getTriggeringElement()['#ash_index'], TRUE);
  }

  /**
   * Submit handler: revisits the fixed values from the summary.
   */
  public function changeConstants(array &$form, FormStateInterface $form_state): void {
    $this->go($form_state, 'constants', 0, TRUE);
  }

  /**
   * Submit handler: revisits the setup from the summary.
   */
  public function changeSetup(array &$form, FormStateInterface $form_state): void {
    $this->go($form_state, 'setup');
  }

  /**
   * AJAX callback: the rebuilt step.
   */
  public function refresh(array $form): array {
    return $form;
  }

  /**
   * Moves to a step.
   */
  protected function go(FormStateInterface $form_state, string $step, int $index = 0, bool $returning = FALSE): void {
    $form_state->set('step', $step);
    $form_state->set('index', $index);
    $form_state->set('returning', $returning);
    // Fixed values are the only inputs a later visit could find stale. Leave
    // the rest: AJAX recognises its own request by the submitted form_id.
    $input = $form_state->getUserInput();
    unset($input['constants']);
    $form_state->setUserInput($input);
    $form_state->setRebuild();
  }

  /**
   * A button that moves the wizard without saving.
   */
  protected function button($label, string $name, string $submit, ?string $target): array {
    return [
      '#type' => 'submit',
      '#value' => $label,
      '#name' => $name,
      '#submit' => [$submit],
      // Setup validates everything; fixed values pass only their own
      // inputs; every other step is a click carrying no input at all.
      '#limit_validation_errors' => match ($submit) {
        '::start' => NULL,
        '::saveConstants' => [['constants']],
        default => [],
      },
      '#ajax' => ['callback' => '::refresh', 'wrapper' => self::WRAPPER],
      '#ash_target' => $target,
    ];
  }

  /**
   * A Salesforce field's posted name, length and options, for a caption.
   */
  protected function meta(Field $field): string {
    $parts = ['<code>' . Html::escape($field->name) . '</code>'];
    if ($field->maxlength) {
      $parts[] = $this->t('max @n', ['@n' => $field->maxlength]);
    }
    if ($field->options) {
      $parts[] = Html::escape(implode(', ', $field->options));
    }
    return implode(' · ', $parts);
  }

  /**
   * The site form's fields worth mapping.
   */
  protected function elements(): array {
    [$source_id, $bundle] = $this->entity->getSource();
    $source = $this->sources->get($source_id);
    return $source && $bundle !== '' ? $source->elements($bundle) : [];
  }

  /**
   * The Salesforce form the mapping posts to, read.
   */
  protected function parsed(): ?ParsedForm {
    $entity = $this->entityTypeManager->getStorage('ash_salesforce_form')->load($this->entity->getSalesforceForm());
    try {
      return $entity ? $this->parser->parse($entity->getHtml()) : NULL;
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Salesforce fields free for a fixed value: unmapped ones and standard ones.
   *
   * @return \Drupal\ash_salesforce\Salesforce\Field[]
   *   Keyed by mapping key.
   */
  protected function constantTargets(): array {
    $parsed = $this->parsed();
    $mapped = array_flip(array_diff($this->entity->getFields(), [Mapping::NONE]));
    return array_diff_key($parsed->fields + $parsed->standard, $mapped);
  }

}
