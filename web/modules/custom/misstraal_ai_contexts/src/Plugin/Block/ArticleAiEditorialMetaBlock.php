<?php

namespace Drupal\misstraal_ai_contexts\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Displays AI + editorial metadata for Article nodes.
 */
#[Block(
  id: "misstraal_article_ai_editorial_meta",
  admin_label: new TranslatableMarkup("Article AI/Editorial panel (Misstraal)"),
  category: new TranslatableMarkup("MissTraal"),
)]
final class ArticleAiEditorialMetaBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // This block is injected on the node edit form. We resolve the node from the
    // current route to avoid ContextDefinition assertions.
    $node = \Drupal::routeMatch()->getParameter('node');
    if (!($node instanceof NodeInterface) || $node->bundle() !== 'article') {
      return [];
    }

    $items = [];
    $items[] = $this->fieldItem($node, 'field_ai_score', $this->t('AI score'));
    $items[] = $this->fieldItem($node, 'field_ai_reasoning', $this->t('AI reasoning'));
    $items[] = $this->fieldItem($node, 'field_editorial_score', $this->t('Editorial score'));
    $items[] = $this->fieldItem($node, 'field_editorial_source', $this->t('Editorial source'));
    $items[] = $this->fieldItem($node, 'field_editorial_subject', $this->t('Editorial subject'));
    $items[] = $this->fieldItem($node, 'field_editorial_description', $this->t('Editorial description'));

    // Remove empty items.
    $items = array_values(array_filter($items, static fn($item) => !empty($item['value'])));

    return [
      '#theme' => 'misstraal_article_ai_editorial_meta_panel',
      '#title' => $this->t('AI & editorial metadata'),
      '#items' => $items,
      '#cache' => [
        'contexts' => ['route'],
        'tags' => Cache::mergeTags($this->getCacheTags(), $node->getCacheTags()),
      ],
    ];
  }

  /**
   * Build a label/value item from a field.
   */
  private function fieldItem(NodeInterface $node, string $field_name, TranslatableMarkup $label): array {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return [
        'label' => (string) $label,
        'value' => NULL,
      ];
    }

    $field = $node->get($field_name);

    // Prefer processed value for formatted text fields if available.
    $first = $field->first();
    $value = NULL;

    if (isset($first->processed)) {
      $value = $first->processed;
    }
    elseif (isset($first->value)) {
      $value = $first->value;
    }

    // Normalize to string.
    if (is_array($value)) {
      $value = implode("\n", $value);
    }

    return [
      'label' => (string) $label,
      'value' => $value,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), ['node_form']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}

