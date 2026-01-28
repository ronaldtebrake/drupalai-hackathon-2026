<?php

declare(strict_types=1);

namespace Drupal\misstraal_ai_contexts\Plugin\FlowDropNodeProcessor;

use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Guideline Prompt Builder node processor for FlowDrop workflows.
 *
 * Builds prompts from ai_context pools for content scoring evaluation.
 */
#[FlowDropNodeProcessor(
  id: "guideline_scoring",
  label: new TranslatableMarkup("Guideline Prompt Builder"),
  description: "Builds prompts from ai_context pools for content scoring evaluation",
  version: "1.0.0"
)]
class GuidelineScoringNode extends AbstractFlowDropNodeProcessor {

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    // Content can come from various sources, so we don't validate here.
    return ValidationResult::success();
  }

  /**
   * Loads agent pool configuration and returns context IDs.
   *
   * @param string $agent_pool_id
   *   The agent pool ID.
   *
   * @return array
   *   Array of context IDs to include.
   */
  protected function loadAgentPoolContexts(string $agent_pool_id): array {
    $config = \Drupal::config('ai_context.agent_pools');
    $agents = $config->get('agents') ?? [];

    foreach ($agents as $agent) {
      if (isset($agent['id']) && $agent['id'] === $agent_pool_id) {
        return $agent['always_include'] ?? [];
      }
    }

    return [];
  }

  /**
   * Loads ai_context entities and aggregates their content.
   *
   * @param array $context_ids
   *   Array of context IDs to load.
   *
   * @return string
   *   Aggregated content from all contexts.
   */
  protected function aggregateContextContent(array $context_ids): string {
    if (empty($context_ids)) {
      return '';
    }

    $storage = \Drupal::entityTypeManager()->getStorage('ai_context');
    $contexts = $storage->loadMultiple($context_ids);

    $content_parts = [];
    foreach ($contexts as $context) {
      if (!$context) {
        continue;
      }
      
      // ai_context entities extend ConfigEntityBase and use get() to access properties.
      // See: web/modules/contrib/ai_context/src/Entity/AiContext.php
      // The module's services use: $entity->get('content')
      // See: web/modules/contrib/ai_context/src/Service/AiContextRenderer.php:70
      // Use toArray() to access properties safely.
      $context_data = $context->toArray();
      $content = trim((string) ($context_data['content'] ?? ''));
      $label = $context->label();
      
      if (!empty($content)) {
        $content_parts[] = "## {$label}\n\n{$content}";
      }
      else {
        \Drupal::logger('misstraal_ai_contexts')->warning('Context "@id" has empty content', [
          '@id' => $context->id(),
        ]);
      }
    }

    return implode("\n\n---\n\n", $content_parts);
  }

  /**
   * Builds the prompt with guidelines and scoring instructions.
   *
   * @param string $content
   *   The content to be scored.
   * @param string $guidelines
   *   The aggregated guidelines content.
   *
   * @return string
   *   The complete prompt.
   */
  protected function buildPrompt(string $content, string $guidelines): string {
    $prompt = <<<'PROMPT'

    You are an automated content compliance and UX quality assessor for the European Commission’s online presence within the .europa.eu domain.
    *YOUR TASK*
    Assess CONTENT against RULES_AND_GUIDELINES and return one single JSON object suitable for automated ingestion.
     *INPUTS YOU WILL RECEIVE*
    You will receive two inputs, wrapped exactly as follows:
    ----------
    RULES_AND_GUIDELINES:
    
{guidelines}
<<<

CONTENT:
<<<
{content}
<<<


Your assessment must follow these constraints:

Sentence-triggered issuesIdentify issues only where a specific sentence or consecutive sentences trigger non-compliance.
Each issue must map directly to the sentence(s) that caused it.
Chronological orderingIssues must appear in the same order as the triggering sentences occur in CONTENT, from beginning to end.
Root-cause groupingIf multiple adjacent sentences reflect the same underlying issue, group them into one issue.
Title-driven classificationDo not output categories or subcategories.
Each issue must instead have a short, neutral, descriptive title that clearly signals the nature of the problem (e.g. “Promotional tone”, “Missing accessibility reference”, “Unclear call to action”).

*SCORING MODEL (MANDATORY)*
For each identified issue, assign:

score: integer from 0–1000 = non-compliant / severe failure
50 = partially compliant / material improvement needed
100 = fully compliant (do not create an issue for “no issue”)
*ISSUE IDENTIFICATION RULES*

Identify only real issues evidenced in CONTENT relative to STYLE_GUIDE.
Do not invent missing information unless explicitly required for this page type.

*OUTPUT REQUIREMENTS (STRICT)*
You MUST always output a valid JSON object. It can be an empty object if you find no issues.
For each individual issue you output in the following structure:

OUTPUT REQUIREMENTS
You MUST output a valid JSON object with the following structure:
{
  "ai_score": 85,
  "subject": "Low readability score",
  "ai_reasoning": "Content follows most AI optimization guidelines. The structure is clear and machine-readable, with descriptive headings. However, some key terms lack explicit definitions that would help LLM understanding.",
  "editorial_score": 90,
  "editorial_reasoning": "Editorial style is consistent and follows the style guide. Grammar and punctuation are correct. The content uses active voice appropriately. Minor improvement: some acronyms could be expanded on first use."
}


The score is a range from 0-100. 0 means low severity, 100 is critical severity.
*Important*: The description of the issue should be CONCISE, and REFERENCE to or QUOTE the element or text with the issue.
The categories you can classify as are:
- accessibility
- editorial
- discoverability
- information_structure
- accuracy
- process

PROMPT;

    return str_replace(
      ['{guidelines}', '{content}'],
      [$guidelines, $content],
      $prompt
    );
  }

  /**
   * Extracts content from various input formats.
   *
   * @param mixed $input
   *   The input data (string, array, etc.).
   *
   * @return string
   *   Extracted content string.
   */
  protected function extractContentFromInput($input): string {
    // If it's already a string, return it.
    if (is_string($input)) {
      return trim($input);
    }

    // If it's an array, try to extract content from common fields.
    if (is_array($input)) {
      // Check for direct content/response fields (from ChatModelNode, etc.)
      $response_fields = ['response', 'raw_response', 'content', 'text', 'output'];
      foreach ($response_fields as $field) {
        if (isset($input[$field]) && is_string($input[$field])) {
          return trim($input[$field]);
        }
      }

      // Check for nested response (e.g., input.response)
      if (isset($input['input']) && is_array($input['input'])) {
        foreach ($response_fields as $field) {
          if (isset($input['input'][$field]) && is_string($input['input'][$field])) {
            return trim($input['input'][$field]);
          }
        }
      }

      // Try common content field names in order of preference.
      $content_fields = [
        'body',
        'field_content',
        'field_body',
        'field_description',
      ];

      // Check if data contains an entity with fields structure (FlowDrop trigger format).
      if (isset($input['entity']) && is_array($input['entity'])) {
        $entity = $input['entity'];
        
        // First, check entity.fields structure (FlowDrop trigger format: entity.fields.field_name[0].value)
        if (isset($entity['fields']) && is_array($entity['fields'])) {
          foreach ($content_fields as $field) {
            if (isset($entity['fields'][$field]) && is_array($entity['fields'][$field])) {
              $field_value = $entity['fields'][$field];
              if (isset($field_value[0]['value'])) {
                return trim((string) $field_value[0]['value']);
              }
              elseif (isset($field_value['value'])) {
                return trim((string) $field_value['value']);
              }
            }
          }
          
          // Also check title field
          if (isset($entity['fields']['title'][0]['value'])) {
            $title = trim((string) $entity['fields']['title'][0]['value']);
            if (!empty($title)) {
              return $title;
            }
          }
        }
        
        // Fallback: check entity directly (for other formats)
        foreach ($content_fields as $field) {
          if (isset($entity[$field])) {
            $field_value = $entity[$field];
            // Handle different field value formats.
            if (is_array($field_value)) {
              // Could be [['value' => '...']] or ['value' => '...']
              if (isset($field_value[0]['value'])) {
                return trim((string) $field_value[0]['value']);
              }
              elseif (isset($field_value['value'])) {
                return trim((string) $field_value['value']);
              }
              elseif (isset($field_value[0])) {
                return trim((string) $field_value[0]);
              }
            }
            elseif (is_string($field_value)) {
              return trim($field_value);
            }
          }
        }
        
        // Also check title directly on entity
        if (isset($entity['title']) && is_string($entity['title'])) {
          return trim($entity['title']);
        }
      }

      // Check direct fields in data.
      foreach ($content_fields as $field) {
        if (isset($input[$field])) {
          $field_value = $input[$field];
          if (is_array($field_value) && isset($field_value[0]['value'])) {
            return trim((string) $field_value[0]['value']);
          }
          elseif (is_array($field_value) && isset($field_value['value'])) {
            return trim((string) $field_value['value']);
          }
          elseif (is_string($field_value)) {
            return trim($field_value);
          }
        }
      }

      // If no content field found, try to serialize the data as a fallback.
      if (!empty($input)) {
        return json_encode($input, JSON_PRETTY_PRINT);
      }
    }

    return '';
  }

  /**
   * Extracts entity context from trigger data.
   *
   * @param mixed $data
   *   The trigger data.
   *
   * @return array
   *   Array with entity_id, entity_type, and bundle.
   */
  protected function extractEntityContext($data): array {
    $context = [
      'entity_id' => '',
      'entity_type' => '',
      'bundle' => '',
    ];

    if (is_array($data)) {
      // Check for direct entity context fields.
      if (isset($data['entity_id'])) {
        $context['entity_id'] = (string) $data['entity_id'];
      }
      if (isset($data['entity_type'])) {
        $context['entity_type'] = (string) $data['entity_type'];
      }
      if (isset($data['bundle'])) {
        $context['bundle'] = (string) $data['bundle'];
      }

      // Check nested entity structure (from trigger).
      if (isset($data['entity']) && is_array($data['entity'])) {
        $entity = $data['entity'];
        if (empty($context['entity_id']) && isset($entity['id'])) {
          $context['entity_id'] = (string) $entity['id'];
        }
        if (empty($context['entity_type']) && isset($entity['entity_type'])) {
          $context['entity_type'] = (string) $entity['entity_type'];
        }
        if (empty($context['bundle']) && isset($entity['bundle'])) {
          $context['bundle'] = (string) $entity['bundle'];
        }
      }
    }

    return $context;
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $content = '';

    // Priority 1: Try 'data' parameter (from trigger output) - primary use case.
    $dataParam = $params->get('data', NULL);
    if ($dataParam !== NULL) {
      $content = $this->extractContentFromInput($dataParam);
    }

    // Priority 2: Try direct 'content' parameter.
    if (empty($content)) {
      $contentParam = $params->get('content', NULL);
      if ($contentParam !== NULL) {
        $content = $this->extractContentFromInput($contentParam);
      }
    }

    // Priority 3: Try unified input port (for connected nodes).
    if (empty($content)) {
      $unifiedInput = $params->get('input', NULL);
      if ($unifiedInput !== NULL) {
        $content = $this->extractContentFromInput($unifiedInput);
      }
    }

    // Priority 4: Try 'response' parameter (from ChatModelNode output) - fallback only.
    if (empty($content)) {
      $responseParam = $params->get('response', NULL);
      if ($responseParam !== NULL) {
        $content = $this->extractContentFromInput($responseParam);
      }
    }

    // Priority 5: Try 'raw_response' parameter (from ChatModelNode output) - fallback only.
    if (empty($content)) {
      $rawResponseParam = $params->get('raw_response', NULL);
      if ($rawResponseParam !== NULL) {
        $content = $this->extractContentFromInput($rawResponseParam);
      }
    }

    // Extract entity context from various sources to pass through.
    $entityContext = [
      'entity_id' => '',
      'entity_type' => '',
      'bundle' => '',
    ];

    // Try 'data' parameter (from trigger output).
    if ($dataParam !== NULL) {
      $entityContext = $this->extractEntityContext($dataParam);
    }

    // Try direct entity context parameters (passed through from previous nodes).
    if (empty($entityContext['entity_id'])) {
      $entityId = $params->get('entity_id', NULL);
      if ($entityId !== NULL) {
        $entityContext['entity_id'] = (string) $entityId;
      }
    }
    if (empty($entityContext['entity_type'])) {
      $entityType = $params->get('entity_type', NULL);
      if ($entityType !== NULL) {
        $entityContext['entity_type'] = (string) $entityType;
      }
    }
    if (empty($entityContext['bundle'])) {
      $bundle = $params->get('bundle', NULL);
      if ($bundle !== NULL) {
        $entityContext['bundle'] = (string) $bundle;
      }
    }

    $agentPoolId = $params->getString('agent_pool_id', 'europa_web_guide_expert');

    try {
      // Validate content is not empty.
      $content = trim($content);
      if (empty($content)) {
        $allParams = $params->all();
        \Drupal::logger('misstraal_ai_contexts')->error('Empty content received. Available params: @params', [
          '@params' => json_encode(array_keys($allParams)),
        ]);
        throw new \RuntimeException('Content cannot be empty. Please connect the trigger\'s "data" output to this node\'s "data" input, or provide content via the "content" parameter.');
      }

      // Load agent pool contexts.
      $context_ids = $this->loadAgentPoolContexts($agentPoolId);
      if (empty($context_ids)) {
        \Drupal::logger('misstraal_ai_contexts')->warning('No contexts found for agent pool: @pool', [
          '@pool' => $agentPoolId,
        ]);
        throw new \RuntimeException("No contexts found for agent pool: {$agentPoolId}");
      }

      // Aggregate context content.
      $guidelines = $this->aggregateContextContent($context_ids);
      if (empty($guidelines)) {
        throw new \RuntimeException('No guideline content could be loaded from contexts');
      }

      // Build prompt.
      $prompt = $this->buildPrompt($content, $guidelines);

      $output = [
        'prompt' => $prompt,
      ];

      // Pass through entity context if available.
      if (!empty($entityContext['entity_id'])) {
        $output['entity_id'] = $entityContext['entity_id'];
      }
      if (!empty($entityContext['entity_type'])) {
        $output['entity_type'] = $entityContext['entity_type'];
      }
      if (!empty($entityContext['bundle'])) {
        $output['bundle'] = $entityContext['bundle'];
      }

      return $output;
    }
    catch (\Exception $e) {
      \Drupal::logger('misstraal_ai_contexts')->error('Guideline prompt builder error: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Failed to build prompt: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'content' => [
          'type' => 'mixed',
          'title' => 'Content',
          'description' => 'The content to be scored (can be string or entity data array)',
          'required' => FALSE,
        ],
        'data' => [
          'type' => 'mixed',
          'title' => 'Data',
          'description' => 'Data from trigger or other nodes',
          'required' => FALSE,
        ],
        'entity_id' => [
          'type' => 'string',
          'title' => 'Entity ID',
          'description' => 'The entity ID (passed through from trigger)',
          'required' => FALSE,
        ],
        'entity_type' => [
          'type' => 'string',
          'title' => 'Entity Type',
          'description' => 'The entity type (passed through from trigger)',
          'required' => FALSE,
        ],
        'bundle' => [
          'type' => 'string',
          'title' => 'Bundle',
          'description' => 'The entity bundle (passed through from trigger)',
          'required' => FALSE,
        ],
        'input' => [
          'type' => 'mixed',
          'title' => 'Input',
          'description' => 'Input from any previous node (unified input port)',
          'required' => FALSE,
        ],
        'response' => [
          'type' => 'string',
          'title' => 'Response',
          'description' => 'Response from a previous node (e.g., ChatModel response output)',
          'required' => FALSE,
        ],
        'raw_response' => [
          'type' => 'string',
          'title' => 'Raw Response',
          'description' => 'Raw response from a previous node (e.g., ChatModel raw_response output)',
          'required' => FALSE,
        ],
        'agent_pool_id' => [
          'type' => 'string',
          'title' => 'Agent Pool ID',
          'description' => 'The agent pool ID to use for loading contexts (defaults to europa_web_guide_expert)',
          'default' => 'europa_web_guide_expert',
        ],
      ],
      'required' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'prompt' => [
          'type' => 'string',
          'title' => 'Prompt',
          'description' => 'The built prompt with guidelines and content for scoring evaluation',
        ],
        'entity_id' => [
          'type' => 'string',
          'title' => 'Entity ID',
          'description' => 'The entity ID (passed through from trigger)',
        ],
        'entity_type' => [
          'type' => 'string',
          'title' => 'Entity Type',
          'description' => 'The entity type (passed through from trigger)',
        ],
        'bundle' => [
          'type' => 'string',
          'title' => 'Bundle',
          'description' => 'The entity bundle (passed through from trigger)',
        ],
      ],
    ];
  }

}
