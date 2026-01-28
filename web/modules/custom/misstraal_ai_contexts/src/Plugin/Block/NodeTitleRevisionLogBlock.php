<?php

namespace Drupal\misstraal_ai_contexts\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Displays node title and revision log message.
 */
#[Block(
  id: "misstraal_node_title_revision_log",
  admin_label: new TranslatableMarkup("Node Title & Revision Log (Misstraal)"),
  category: new TranslatableMarkup("MissTraal"),
)]
final class NodeTitleRevisionLogBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // This block is injected on the node edit form. We resolve the node from the
    // current route to avoid ContextDefinition assertions.
    $node = \Drupal::routeMatch()->getParameter('node');
    if (!($node instanceof NodeInterface)) {
      return [];
    }

    $items = [];

    // Add node title
    $items[] = [
      'label' => (string) $this->t('Title'),
      'value' => $node->getTitle(),
    ];

    // Add revision log message
    $revision_log = $node->getRevisionLogMessage();
    $items[] = [
      'label' => (string) $this->t('Revision log message'),
      'value' => $revision_log ?: NULL,
    ];

    // Remove empty items.
    $items = array_values(array_filter($items, static fn($item) => !empty($item['value'])));

    return [
      '#theme' => 'misstraal_article_ai_editorial_meta_panel',
      '#title' => $this->t('Node information'),
      '#items' => $items,
      '#cache' => [
        'contexts' => ['route'],
        'tags' => Cache::mergeTags($this->getCacheTags(), $node->getCacheTags()),
      ],
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
