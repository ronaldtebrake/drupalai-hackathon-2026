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
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Chat Model node processor for FlowDrop workflows.
 *
 * Calls AI chat models using the Drupal AI module.
 */
#[FlowDropNodeProcessor(
  id: "chat_model",
  label: new TranslatableMarkup("Chat Model"),
  description: "Calls AI chat models using the Drupal AI module",
  version: "1.0.0"
)]
class ChatModelNode extends AbstractFlowDropNodeProcessor {

  /**
   * Constructs a ChatModelNode object.
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
    $prompt = $params['prompt'] ?? NULL;
    if (empty($prompt)) {
      return ValidationResult::error('prompt', 'Prompt is required');
    }
    return ValidationResult::success();
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    // Check for prompt in unified input port first, then direct parameter.
    $unifiedInput = $params->get('input', NULL);
    if (is_array($unifiedInput) && isset($unifiedInput['prompt'])) {
      $prompt = (string) $unifiedInput['prompt'];
    }
    else {
      $prompt = $params->getString('prompt', '');
    }

    $systemMessage = $params->getString('system_message', '');
    $model = $params->getString('model', '');
    $temperature = $params->getFloat('temperature', 0.7);
    $responseFormat = $params->getString('response_format', '');

    try {
      // Validate prompt is not empty.
      $prompt = trim($prompt);
      if (empty($prompt)) {
        $allParams = $params->all();
        \Drupal::logger('misstraal_ai_contexts')->error('Empty prompt received. Available params: @params. Make sure the "prompt" parameter is marked as connectable in the node type configuration, or connect the prompt output from a previous node.', [
          '@params' => json_encode(array_keys($allParams)),
        ]);
        throw new \RuntimeException('Prompt cannot be empty. Please ensure: 1) The "prompt" parameter is marked as connectable in the node type configuration, 2) A prompt value is provided via input connection or node configuration, or 3) The prompt output from a previous node (e.g., PromptTemplate) is connected to this node\'s prompt input.');
      }

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

      // Prepare chat messages - ensure we have at least one message with content.
      $userMessage = new ChatMessage('user', $prompt);
      $messages = new ChatInput([$userMessage]);

      // Verify the message was created correctly.
      $messageArray = $messages->getMessages();
      if (empty($messageArray) || empty($messageArray[0]->getText())) {
        \Drupal::logger('misstraal_ai_contexts')->error('Failed to create valid message. Prompt: @prompt', [
          '@prompt' => $prompt,
        ]);
        throw new \RuntimeException('Failed to create valid chat message. Message text cannot be empty.');
      }

      // Set system message if provided.
      if (!empty($systemMessage)) {
        $messages->setSystemPrompt(trim($systemMessage));
      }

      // Configure model settings.
      $modelConfig = [];
      if ($temperature !== 0.7) {
        $modelConfig['temperature'] = $temperature;
      }
      if ($responseFormat === 'json_object') {
        $modelConfig['response_format'] = ['type' => 'json_object'];
      }

      if (!empty($modelConfig)) {
        $provider->setConfiguration($modelConfig);
      }

      // Call the AI provider.
      $response = $provider->chat($messages, $modelId);

      // Get the normalized response.
      $normalized = $response->getNormalized();
      $text = $normalized->getText();

      return [
        'response' => $text,
        'raw_response' => $text,
        'model' => $modelId,
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('misstraal_ai_contexts')->error('AI chat model error: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Failed to call AI model: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'prompt' => [
          'type' => 'string',
          'title' => 'Prompt',
          'description' => 'The input prompt to send to the AI model',
          'required' => TRUE,
        ],
        'system_message' => [
          'type' => 'string',
          'title' => 'System Message',
          'description' => 'Optional system message to set context for the AI',
          'default' => '',
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
        'response_format' => [
          'type' => 'string',
          'title' => 'Response Format',
          'description' => 'Response format (e.g., "json_object" for structured JSON)',
          'default' => '',
          'enum' => ['', 'json_object'],
        ],
      ],
      'required' => ['prompt'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'response' => [
          'type' => 'string',
          'title' => 'Response',
          'description' => 'The AI model response text',
        ],
        'raw_response' => [
          'type' => 'string',
          'title' => 'Raw Response',
          'description' => 'The raw response from the AI model',
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
