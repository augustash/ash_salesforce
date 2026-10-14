<?php

namespace Drupal\ash_salesforce\Form;

use Drupal\ash_salesforce\Entity\SalesforceForm;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Takes the form HTML Salesforce generated and shows what was read from it.
 */
class SalesforceFormForm extends EntityForm {

  /**
   * The Salesforce form parser.
   *
   * @var \Drupal\ash_salesforce\Salesforce\FormParser
   */
  protected $parser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = parent::create($container);
    $form->parser = $container->get('ash_salesforce.form_parser');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\ash_salesforce\Entity\SalesforceForm $entity */
    $entity = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#description' => $this->t('Name the org it came from, e.g. "Cases (UAT sandbox)".'),
      '#default_value' => $entity->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => ['exists' => [SalesforceForm::class, 'load']],
      '#disabled' => !$entity->isNew(),
    ];
    $form['html'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Generated form'),
      '#description' => $this->t('Paste the whole Web-to-Case or Web-to-Lead HTML from Salesforce Setup, as generated. It holds the org ID and the field IDs, so a sandbox and production each need their own.'),
      '#default_value' => $entity->getHtml(),
      '#rows' => 16,
      '#required' => TRUE,
    ];

    if (!$entity->isNew() && $entity->getHtml()) {
      $form['parsed'] = $this->summary($entity->getHtml());
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    try {
      $parsed = $this->parser->parse((string) $form_state->getValue('html'));
      if (!$parsed->fields) {
        $form_state->setErrorByName('html', $this->t('The form has no fields to send to.'));
      }
    }
    catch (\InvalidArgumentException) {
      $form_state->setErrorByName('html', $this->t('This is not a Salesforce Web-to-Case or Web-to-Lead form. Paste the HTML exactly as Salesforce generated it.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('edit-form'));
    return $result;
  }

  /**
   * A table of what was read, so a bad paste is obvious.
   */
  protected function summary(string $html): array {
    try {
      $parsed = $this->parser->parse($html);
    }
    catch (\InvalidArgumentException) {
      return [];
    }
    $rows = [];
    foreach ($parsed->fields as $field) {
      $rows[] = [
        $field->label,
        ['data' => ['#markup' => '<code>' . $field->name . '</code>']],
        $field->maxlength ?? '',
        implode(', ', $field->options),
      ];
    }
    return [
      '#type' => 'details',
      '#title' => $this->t('Read from the form: @kind to @host', [
        '@kind' => $parsed->kind === 'case' ? $this->t('Cases') : $this->t('Leads'),
        '@host' => parse_url($parsed->action, PHP_URL_HOST),
      ]),
      '#open' => TRUE,
      '#weight' => -10,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Field'), $this->t('Posted as'), $this->t('Max length'), $this->t('Options')],
        '#rows' => $rows,
      ],
    ];
  }

}
