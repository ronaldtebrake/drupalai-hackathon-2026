<?php

declare(strict_types=1);

namespace Drupal\misstraal_ai_contexts\Plugin\FlowDropNodeProcessor;

use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Guideline Scoring node processor for FlowDrop workflows.
 *
 * Builds prompts from ai_context pools and scores content in two categories.
 */
#[FlowDropNodeProcessor(
  id: "guideline_scoring",
  label: new TranslatableMarkup("Guideline Scoring"),
  description: "Builds prompts from ai_context pools and scores content in two categories (AI and Editorial)",
  version: "1.0.0"
)]
class GuidelineScoringNode extends AbstractFlowDropNodeProcessor {

  /**
   * Constructs a GuidelineScoringNode object.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\ai\AiProviderPluginManager $aiProviderPluginManager
   *   AI provider plugin manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly AiProviderPluginManager $aiProviderPluginManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.provider'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    $content = $params['content'] ?? NULL;
    if (empty($content)) {
      return ValidationResult::error('content', 'Content is required');
    }
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
      $content = trim((string) ($context->get('content') ?? ''));
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
You are a web content quality assessor evaluating content against style guidelines.

STYLE_GUIDE:
<<<
{guidelines}
<<<

CONTENT:
<<<
{content}
<<<

YOUR TASK
Evaluate the CONTENT against the STYLE_GUIDE and provide scores in two categories:

1. **AI Score** (0-100): Overall compliance with AI/LLM optimization guidelines, including:
   - Clarity and machine-readability
   - Structured information
   - SEO-for-LLMs best practices
   - Discoverability and accessibility for AI systems

2. **Editorial Score** (0-100): Compliance with editorial and style guidelines, including:
   - Writing style and tone
   - Grammar and punctuation
   - Formatting and structure
   - Consistency with style guide rules

For each category, provide:
- A numeric score from 0-100 (where 0 = non-compliant/severe failure, 50 = partially compliant, 100 = fully compliant)
- A concise reasoning explaining the score, referencing specific guidelines and content elements

OUTPUT REQUIREMENTS
You MUST output a valid JSON object with the following structure:
{
  "ai_score": 85,
  "ai_reasoning": "Content follows most AI optimization guidelines. The structure is clear and machine-readable, with descriptive headings. However, some key terms lack explicit definitions that would help LLM understanding.",
  "editorial_score": 90,
  "editorial_reasoning": "Editorial style is consistent and follows the style guide. Grammar and punctuation are correct. The content uses active voice appropriately. Minor improvement: some acronyms could be expanded on first use."
}

The scores must be integers between 0 and 100.
The reasoning should be concise but specific, referencing the guidelines and pointing to specific content elements where applicable.
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

      // Check if data contains an entity.
      if (isset($input['entity']) && is_array($input['entity'])) {
        $entity = $input['entity'];
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
   * Strips markdown code blocks from JSON response.
   *
   * @param string $text
   *   The response text that may contain markdown code blocks.
   *
   * @return string
   *   Cleaned JSON string.
   */
  protected function stripMarkdownCodeBlocks(string $text): string {
    // Remove markdown code blocks (```json ... ``` or ``` ... ```)
    // Handle both literal newlines and escaped \n sequences
    $text = trim($text);
    
    // Remove opening code block markers (```json or ```) at start
    // Handle both actual newlines and escaped \n
    $text = preg_replace('/^```(?:json)?\s*(?:\\n|\n)?/i', '', $text);
    
    // Remove closing code block markers (```) at end
    $text = preg_replace('/(?:\\n|\n)?```\s*$/i', '', $text);
    
    // Also remove any remaining code block markers anywhere in the string
    $text = preg_replace('/```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```/i', '', $text);
    
    // If the string still contains escaped newlines, convert them to actual newlines
    // This handles cases where the response has literal \n characters
    $text = str_replace('\\n', "\n", $text);
    
    return trim($text);
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $content = '';

    // Try unified input port first (most common for connected nodes).
    $unifiedInput = $params->get('input', NULL);
    if ($unifiedInput !== NULL) {
      $content = $this->extractContentFromInput($unifiedInput);
    }

    // Try 'response' parameter (from ChatModelNode output) - check this early.
    if (empty($content)) {
      $responseParam = $params->get('response', NULL);
      if ($responseParam !== NULL) {
        $content = $this->extractContentFromInput($responseParam);
      }
    }

    // Try 'raw_response' parameter (from ChatModelNode output).
    if (empty($content)) {
      $rawResponseParam = $params->get('raw_response', NULL);
      if ($rawResponseParam !== NULL) {
        $content = $this->extractContentFromInput($rawResponseParam);
      }
    }

    // Try direct 'content' parameter.
    if (empty($content)) {
      $contentParam = $params->get('content', NULL);
      if ($contentParam !== NULL) {
        $content = $this->extractContentFromInput($contentParam);
      }
    }

    // Try 'data' parameter (from trigger output).
    if (empty($content)) {
      $dataParam = $params->get('data', NULL);
      if ($dataParam !== NULL) {
        $content = $this->extractContentFromInput($dataParam);
      }
    }

    $agentPoolId = $params->getString('agent_pool_id', 'europa_web_guide_expert');
    $model = $params->getString('model', '');
    $temperature = $params->getFloat('temperature', 0.7);

    try {
      // Validate content is not empty.
      $content = trim($content);
      if (empty($content)) {
        $allParams = $params->all();
        \Drupal::logger('misstraal_ai_contexts')->error('Empty content received. Available params: @params', [
          '@params' => json_encode(array_keys($allParams)),
        ]);
        throw new \RuntimeException('Content cannot be empty. Please connect the output from a previous node (e.g., ChatModel response) or provide content via the "content" parameter.');
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

      // Get default provider for chat operations.
      $defaults = $this->aiProviderPluginManager->getDefaultProviderForOperationType('chat');

      if (empty($defaults['provider_id']) || empty($defaults['model_id'])) {
        throw new \RuntimeException('No AI provider configured for chat operations');
      }

      // Use provided model or default.
      $modelId = !empty($model) ? $model : $defaults['model_id'];
      $providerId = $defaults['provider_id'];

      // Create provider instance.
      $provider = $this->aiProviderPluginManager->createInstance($providerId);

      // Prepare chat messages.
      $userMessage = new ChatMessage('user', $prompt);
      $messages = new ChatInput([$userMessage]);

      // Configure model settings with JSON response format.
      $modelConfig = [
        'response_format' => ['type' => 'json_object'],
      ];
      if ($temperature !== 0.7) {
        $modelConfig['temperature'] = $temperature;
      }

      $provider->setConfiguration($modelConfig);

      // Call the AI provider.
      $response = $provider->chat($messages, $modelId);

      // Get the normalized response.
      $normalized = $response->getNormalized();
      $text = $normalized->getText();

      // Strip markdown code blocks if present.
      $original_text = $text;
      $text = $this->stripMarkdownCodeBlocks($text);

      // Parse JSON response.
      $parsed = json_decode($text, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        // Log the original response before stripping for debugging
        \Drupal::logger('misstraal_ai_contexts')->error('Failed to parse JSON response: @error. Original (first 1000 chars): @original. Cleaned (first 1000 chars): @cleaned', [
          '@error' => json_last_error_msg(),
          '@original' => substr($original_text, 0, 1000),
          '@cleaned' => substr($text, 0, 1000),
        ]);
        throw new \RuntimeException('Failed to parse JSON response from AI model: ' . json_last_error_msg());
      }

      // Handle different response formats.
      // The AI might return an "issues" array format instead of direct scores.
      // If so, we need to calculate scores from the issues.
      if (isset($parsed['issues']) && is_array($parsed['issues'])) {
        // Calculate scores from issues array.
        $ai_issues = [];
        $editorial_issues = [];
        
        foreach ($parsed['issues'] as $issue) {
          $categorization = $issue['categorization'] ?? '';
          $score = isset($issue['score']) ? (int) $issue['score'] : 0;
          $description = $issue['description'] ?? '';
          
          // Categorize issues into AI vs Editorial based on categorization field
          if (in_array($categorization, ['information_structure', 'discoverability', 'accessibility'])) {
            $ai_issues[] = $issue;
          }
          else {
            $editorial_issues[] = $issue;
          }
        }
        
        // Calculate average scores (invert: lower issue scores = better content)
        // Issue scores are severity (0-100), so we invert: 100 - average
        $ai_avg = !empty($ai_issues) 
          ? (int) (100 - array_sum(array_column($ai_issues, 'score')) / count($ai_issues))
          : 100;
        $editorial_avg = !empty($editorial_issues)
          ? (int) (100 - array_sum(array_column($editorial_issues, 'score')) / count($editorial_issues))
          : 100;
        
        // Build reasoning from issues
        $ai_reasoning_parts = [];
        foreach ($ai_issues as $issue) {
          $ai_reasoning_parts[] = ($issue['subject'] ?? 'Issue') . ': ' . ($issue['description'] ?? '');
        }
        $ai_reasoning = !empty($ai_reasoning_parts) 
          ? implode(' ', $ai_reasoning_parts)
          : 'No AI-related issues found.';
        
        $editorial_reasoning_parts = [];
        foreach ($editorial_issues as $issue) {
          $editorial_reasoning_parts[] = ($issue['subject'] ?? 'Issue') . ': ' . ($issue['description'] ?? '');
        }
        $editorial_reasoning = !empty($editorial_reasoning_parts)
          ? implode(' ', $editorial_reasoning_parts)
          : 'No editorial issues found.';
        
        $ai_score = max(0, min(100, $ai_avg));
        $editorial_score = (string) max(0, min(100, $editorial_avg));
      }
      else {
        // Standard format with direct scores.
        $ai_score = isset($parsed['ai_score']) ? (int) $parsed['ai_score'] : 0;
        $ai_reasoning = isset($parsed['ai_reasoning']) ? (string) $parsed['ai_reasoning'] : '';
        $editorial_score = isset($parsed['editorial_score']) ? (string) $parsed['editorial_score'] : '0';
        $editorial_reasoning = isset($parsed['editorial_reasoning']) ? (string) $parsed['editorial_reasoning'] : '';
      }

      // Validate score ranges.
      $ai_score = max(0, min(100, $ai_score));
      $editorial_score_int = (int) $editorial_score;
      $editorial_score_int = max(0, min(100, $editorial_score_int));
      $editorial_score = (string) $editorial_score_int;

      return [
        'ai_score' => $ai_score,
        'ai_reasoning' => $ai_reasoning,
        'editorial_score' => $editorial_score,
        'editorial_reasoning' => $editorial_reasoning,
        'raw_response' => $text,
        'model' => $modelId,
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('misstraal_ai_contexts')->error('Guideline scoring error: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Failed to score content: ' . $e->getMessage(), 0, $e);
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
          'type' => 'string',
          'title' => 'Content',
          'description' => 'The content to be scored against guidelines',
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
        'data' => [
          'type' => 'mixed',
          'title' => 'Data',
          'description' => 'Data from trigger or other nodes',
          'required' => FALSE,
        ],
        'agent_pool_id' => [
          'type' => 'string',
          'title' => 'Agent Pool ID',
          'description' => 'The agent pool ID to use for loading contexts (defaults to europa_web_guide_expert)',
          'default' => 'europa_web_guide_expert',
        ],
        'model' => [
          'type' => 'string',
          'title' => 'Model',
          'description' => 'Optional model identifier (uses default if not specified)',
          'default' => '',
        ],
        'temperature' => [
          'type' => 'number',
          'title' => 'Temperature',
          'description' => 'Sampling temperature (0.0 to 2.0)',
          'default' => 0.7,
          'minimum' => 0,
          'maximum' => 2,
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
        'ai_score' => [
          'type' => 'integer',
          'title' => 'AI Score',
          'description' => 'Score for AI/LLM optimization category (0-100)',
        ],
        'ai_reasoning' => [
          'type' => 'string',
          'title' => 'AI Reasoning',
          'description' => 'Reasoning for the AI score',
        ],
        'editorial_score' => [
          'type' => 'string',
          'title' => 'Editorial Score',
          'description' => 'Score for editorial/style category (0-100)',
        ],
        'editorial_reasoning' => [
          'type' => 'string',
          'title' => 'Editorial Reasoning',
          'description' => 'Reasoning for the editorial score',
        ],
        'raw_response' => [
          'type' => 'string',
          'title' => 'Raw Response',
          'description' => 'The raw JSON response from the AI model',
        ],
        'model' => [
          'type' => 'string',
          'title' => 'Model',
          'description' => 'The model identifier that was used',
        ],
      ],
    ];
  }

}
