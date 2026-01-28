<?php

declare(strict_types=1);

namespace Drupal\misstraal_ai_contexts\Plugin\FlowDropNodeProcessor;

use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Database\DatabaseExceptionWrapper;

/**
 * Guideline Score Parser node processor for FlowDrop workflows.
 *
 * Parses JSON response from ChatModelNode, extracts scores and reasoning,
 * and saves them to the entity.
 */
#[FlowDropNodeProcessor(
  id: "guideline_score_parser",
  label: new TranslatableMarkup("Guideline Score Parser"),
  description: "Parses JSON response from guideline scoring, extracts scores and reasoning, and saves to entity",
  version: "2.0.0"
)]
class GuidelineScoreParserNode extends AbstractFlowDropNodeProcessor {

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    return ValidationResult::success();
  }

  /**
   * Normalizes JSON string by ensuring UTF-8 encoding and removing problematic characters.
   *
   * @param string $text
   *   The JSON text that may contain control characters or encoding issues.
   *
   * @return string
   *   Normalized JSON string.
   */
  protected function normalizeJsonString(string $text): string {
    // Ensure the string is valid UTF-8
    if (!mb_check_encoding($text, 'UTF-8')) {
      $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    
    // Remove null bytes and other problematic control characters
    // But preserve valid JSON escaped sequences like \n, \t, \r
    // Note: We're being conservative here - only removing truly problematic chars
    // Actual newlines/tabs in JSON strings should be escaped, but we'll let
    // json_decode with JSON_INVALID_UTF8 flags handle encoding issues
    $text = preg_replace('/[\x00]/', '', $text); // Remove null bytes
    
    return $text;
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
    $text = trim($text);
    
    // Strategy 1: Extract content between code block markers using greedy match
    // The 's' flag makes . match newlines, and we use greedy .* to capture everything
    // until the last closing marker
    if (preg_match('/```(?:json|JSON)?\s*\r?\n?(.*)\r?\n?\s*```/is', $text, $matches)) {
      $text = trim($matches[1]);
    }
    // Strategy 2: Handle case where markers might be on same line or have different spacing
    elseif (preg_match('/```(?:json|JSON)?\s*(.*)\s*```/is', $text, $matches)) {
      $text = trim($matches[1]);
    }
    // Strategy 3: If text starts with ```, remove opening marker and find closing
    elseif (preg_match('/^```(?:json|JSON)?\s*(.*)/is', $text, $matches)) {
      $text = $matches[1];
      // Remove closing ``` if present (find last occurrence)
      $lastPos = strrpos($text, '```');
      if ($lastPos !== FALSE) {
        $text = substr($text, 0, $lastPos);
      }
      $text = trim($text);
    }
    
    // Strategy 4: More aggressive removal if markers still present
    if (strpos($text, '```') !== FALSE) {
      // Remove all code block markers
      $text = preg_replace('/```(?:json|JSON)?\s*/i', '', $text);
      $text = preg_replace('/\s*```/i', '', $text);
      $text = trim($text);
    }
    
    // IMPORTANT: Do NOT convert \n to actual newlines here!
    // Escaped newlines (\n) inside JSON strings are VALID and must be preserved.
    // Only convert them if they're outside of JSON string context (which is complex).
    
    // Normalize control characters but preserve valid JSON escaped sequences
    $text = $this->normalizeJsonString($text);
    
    return trim($text);
  }

  /**
   * Attempts to fix common JSON syntax errors.
   *
   * @param string $text
   *   The JSON text that may contain syntax errors.
   *
   * @return string
   *   Potentially fixed JSON string.
   */
  protected function attemptJsonRepair(string $text): string {
    // Fix: "key": , (missing value after colon followed by comma or closing brace)
    // Replace with "key": 0 or "key": "" depending on context
    // For numeric fields, use 0; for string fields, use ""
    $text = preg_replace('/"editorial_score"\s*:\s*,/', '"editorial_score": 0', $text);
    $text = preg_replace('/"ai_score"\s*:\s*,/', '"ai_score": 0', $text);
    $text = preg_replace('/"score"\s*:\s*,/', '"score": 0', $text);
    
    // Fix: "key": ,} or "key": ,] (missing value before closing)
    $text = preg_replace('/"editorial_score"\s*:\s*,(\s*[}\]])/', '"editorial_score": 0$1', $text);
    $text = preg_replace('/"ai_score"\s*:\s*,(\s*[}\]])/', '"ai_score": 0$1', $text);
    $text = preg_replace('/"score"\s*:\s*,(\s*[}\]])/', '"score": 0$1', $text);
    
    // Generic fix for any key with missing value (use empty string as fallback)
    // But avoid replacing keys that already have values
    $text = preg_replace('/"([^"]+)"\s*:\s*,(\s*[}\]])/', '"$1": ""$2', $text);
    $text = preg_replace('/"([^"]+)"\s*:\s*,(\s*,)/', '"$1": ""$2', $text);
    
    // Fix: trailing commas before closing braces/brackets
    $text = preg_replace('/,\s*}/', '}', $text);
    $text = preg_replace('/,\s*]/', ']', $text);
    
    // Fix: multiple commas
    $text = preg_replace('/,\s*,/', ',', $text);
    
    return $text;
  }

  /**
   * Extracts JSON from text that may contain surrounding text.
   *
   * @param string $text
   *   The text that may contain JSON.
   *
   * @return string|null
   *   Extracted JSON string or NULL if not found.
   */
  protected function extractJsonFromText(string $text): ?string {
    $text = trim($text);
    
    // Strategy 1: Try to find JSON object boundaries
    // Look for { ... } pattern
    if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $text, $matches)) {
      $candidate = $matches[0];
      // Normalize and validate it's valid JSON
      $candidate = $this->normalizeJsonString($candidate);
      json_decode($candidate, TRUE, 512, JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE);
      if (json_last_error() === JSON_ERROR_NONE) {
        return $candidate;
      }
    }
    
    // Strategy 2: Try to find JSON starting from first {
    $firstBrace = strpos($text, '{');
    if ($firstBrace !== FALSE) {
      // Find matching closing brace
      $braceCount = 0;
      $endPos = $firstBrace;
      for ($i = $firstBrace; $i < strlen($text); $i++) {
        if ($text[$i] === '{') {
          $braceCount++;
        } elseif ($text[$i] === '}') {
          $braceCount--;
          if ($braceCount === 0) {
            $endPos = $i;
            break;
          }
        }
      }
      
      if ($braceCount === 0) {
        $candidate = substr($text, $firstBrace, $endPos - $firstBrace + 1);
        $candidate = $this->normalizeJsonString($candidate);
        json_decode($candidate, TRUE, 512, JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (json_last_error() === JSON_ERROR_NONE) {
          return $candidate;
        }
      }
    }
    
    // Strategy 3: Try the whole text after cleaning and normalizing
    $normalized = $this->normalizeJsonString($text);
    json_decode($normalized, TRUE, 512, JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (json_last_error() === JSON_ERROR_NONE) {
      return $normalized;
    }
    
    return NULL;
  }

  /**
   * Extracts response text from various input formats.
   *
   * @param mixed $input
   *   The input data (string, array, etc.).
   *
   * @return string
   *   Extracted response text.
   */
  protected function extractResponseText($input): string {
    // If it's already a string, return it.
    if (is_string($input)) {
      return trim($input);
    }

    // If it's an array, try to extract response.
    if (is_array($input)) {
      // Check for direct response fields.
      $response_fields = ['response', 'raw_response'];
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
    $responseText = '';

    // Try 'response' parameter first (from ChatModelNode output).
    $responseParam = $params->get('response', NULL);
    if ($responseParam !== NULL) {
      $responseText = $this->extractResponseText($responseParam);
    }

    // Try 'raw_response' parameter.
    if (empty($responseText)) {
      $rawResponseParam = $params->get('raw_response', NULL);
      if ($rawResponseParam !== NULL) {
        $responseText = $this->extractResponseText($rawResponseParam);
      }
    }

    // Try unified input port.
    if (empty($responseText)) {
      $unifiedInput = $params->get('input', NULL);
      if ($unifiedInput !== NULL) {
        $responseText = $this->extractResponseText($unifiedInput);
      }
    }

    // Extract entity context from various sources (passed through from trigger).
    $entityContext = [
      'entity_id' => '',
      'entity_type' => '',
      'bundle' => '',
    ];

    // Priority 1: Try direct entity context parameters (from ChatModelNode outputs).
    $entityId = $params->get('entity_id', NULL);
    if ($entityId !== NULL) {
      $entityContext['entity_id'] = (string) $entityId;
    }
    $entityType = $params->get('entity_type', NULL);
    if ($entityType !== NULL) {
      $entityContext['entity_type'] = (string) $entityType;
    }
    $bundle = $params->get('bundle', NULL);
    if ($bundle !== NULL) {
      $entityContext['bundle'] = (string) $bundle;
    }

    // Priority 2: Try 'data' parameter (from trigger output) if entity context not found.
    if (empty($entityContext['entity_id'])) {
      $dataParam = $params->get('data', NULL);
      if ($dataParam !== NULL) {
        $extractedContext = $this->extractEntityContext($dataParam);
        if (!empty($extractedContext['entity_id'])) {
          $entityContext = $extractedContext;
        }
      }
    }

    try {
      // Validate response is not empty.
      $responseText = trim($responseText);
      if (empty($responseText)) {
        $allParams = $params->all();
        \Drupal::logger('misstraal_ai_contexts')->error('Empty response received. Available params: @params', [
          '@params' => json_encode(array_keys($allParams)),
        ]);
        throw new \RuntimeException('Response cannot be empty. Please connect the ChatModelNode\'s "response" or "raw_response" output to this node.');
      }

      // Strip markdown code blocks if present.
      $original_text = $responseText;
      $responseText = $this->stripMarkdownCodeBlocks($responseText);

      // Parse JSON response with flags to handle invalid UTF-8 and control characters.
      $parsed = json_decode($responseText, TRUE, 512, JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        // Try one more time with more aggressive cleaning using the improved method
        $responseText = $this->stripMarkdownCodeBlocks($original_text);
        
        // Try parsing again with error handling flags
        $parsed = json_decode($responseText, TRUE, 512, JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
          // Try to extract JSON from the text
          $extractedJson = $this->extractJsonFromText($responseText);
          if ($extractedJson !== NULL) {
            $parsed = json_decode($extractedJson, TRUE, 512, JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (json_last_error() === JSON_ERROR_NONE) {
              $responseText = $extractedJson;
            } else {
              // Try to repair the extracted JSON
              $repairedJson = $this->attemptJsonRepair($extractedJson);
              $parsed = json_decode($repairedJson, TRUE, 512, JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE);
              if (json_last_error() === JSON_ERROR_NONE) {
                $responseText = $repairedJson;
                \Drupal::logger('misstraal_ai_contexts')->warning('Successfully repaired JSON syntax errors in extracted JSON');
              }
            }
          }
        }
        
        // If still failing, try to repair common JSON syntax errors on the full text
        if (json_last_error() !== JSON_ERROR_NONE) {
          $repairedJson = $this->attemptJsonRepair($responseText);
          $parsed = json_decode($repairedJson, TRUE, 512, JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE);
          if (json_last_error() === JSON_ERROR_NONE) {
            $responseText = $repairedJson;
            \Drupal::logger('misstraal_ai_contexts')->warning('Successfully repaired JSON syntax errors in response');
          }
        }
        
        // If still failing, log detailed error and throw exception
        if (json_last_error() !== JSON_ERROR_NONE) {
          $errorDetails = [
            '@error' => json_last_error_msg(),
            '@original_length' => strlen($original_text),
            '@original_preview' => substr($original_text, 0, 500),
            '@cleaned_length' => strlen($responseText),
            '@cleaned_preview' => substr($responseText, 0, 500),
          ];
          
          // Log full response if it's not too long
          if (strlen($original_text) < 5000) {
            $errorDetails['@full_response'] = $original_text;
          }
          
          \Drupal::logger('misstraal_ai_contexts')->error('Failed to parse JSON response: @error. Original preview: @original_preview. Cleaned preview: @cleaned_preview', $errorDetails);
          
          throw new \RuntimeException('Failed to parse JSON response: ' . json_last_error_msg() . '. Response preview: ' . substr($responseText, 0, 200));
        }
      }

      // Extract scores and reasoning.
      $ai_score = isset($parsed['ai_score']) ? (int) $parsed['ai_score'] : 0;
      $ai_reasoning = isset($parsed['ai_reasoning']) ? (string) $parsed['ai_reasoning'] : '';
      $editorial_score = isset($parsed['editorial_score']) ? (string) $parsed['editorial_score'] : '0';
      $editorial_reasoning = isset($parsed['editorial_reasoning']) ? (string) $parsed['editorial_reasoning'] : '';

      // Validate score ranges.
      $ai_score = max(0, min(100, $ai_score));
      $editorial_score_int = (int) $editorial_score;
      $editorial_score_int = max(0, min(100, $editorial_score_int));
      $editorial_score = (string) $editorial_score_int;

      // Save to entity if entity context is available.
      if (!empty($entityContext['entity_id']) && !empty($entityContext['entity_type'])) {
        // Retry logic for handling database lock timeouts.
        $maxRetries = 3;
        $retryDelay = 0.1; // 100ms initial delay
        $saveSuccess = FALSE;

        for ($attempt = 1; $attempt <= $maxRetries && !$saveSuccess; $attempt++) {
          try {
            $entityTypeManager = \Drupal::entityTypeManager();
            $storage = $entityTypeManager->getStorage($entityContext['entity_type']);
            
            // Add small random delay (jitter) on first attempt to spread out
            // concurrent operations and reduce lock contention when multiple processes
            // try to save the same entity simultaneously.
            if ($attempt === 1) {
              // Random delay between 0-50ms to spread out concurrent operations.
              $jitter = mt_rand(0, 50000);
              usleep($jitter);
            }
            
            // On retry, clear cache and add delay to allow locks to clear.
            if ($attempt > 1) {
              $storage->resetCache([$entityContext['entity_id']]);
              usleep((int) ($retryDelay * 1000000 * $attempt));
            }

            // Use loadUnchanged() to load directly from database without triggering
            // cache writes. This reduces lock contention when multiple processes
            // load the same entity concurrently.
            $entity = $storage->loadUnchanged($entityContext['entity_id']);

            if ($entity && $entity instanceof ContentEntityInterface) {
              // Set field values.
              if ($entity->hasField('field_ai_score')) {
                $entity->set('field_ai_score', $ai_score);
              }

              if ($entity->hasField('field_ai_reasoning') && !empty($ai_reasoning)) {
                $entity->set('field_ai_reasoning', $ai_reasoning);
              }

              if ($entity->hasField('field_editorial_score')) {
                $entity->set('field_editorial_score', $editorial_score);
              }

              if ($entity->hasField('field_editorial_description')) {
                $entity->set('field_editorial_description', $editorial_reasoning);
              }

              // Save the entity with retry logic for lock timeouts.
              try {
                $entity->save();
                $saveSuccess = TRUE;

                \Drupal::logger('misstraal_ai_contexts')->info('Successfully saved guideline scores to entity @type:@id', [
                  '@type' => $entityContext['entity_type'],
                  '@id' => $entityContext['entity_id'],
                ]);
              }
              catch (DatabaseExceptionWrapper $e) {
                // Check if it's a lock timeout error (MySQL error 1205).
                if (strpos($e->getMessage(), '1205') !== FALSE || strpos($e->getMessage(), 'Lock wait timeout') !== FALSE) {
                  if ($attempt < $maxRetries) {
                    \Drupal::logger('misstraal_ai_contexts')->warning('Database lock timeout on attempt @attempt for entity @type:@id, retrying...', [
                      '@attempt' => $attempt,
                      '@type' => $entityContext['entity_type'],
                      '@id' => $entityContext['entity_id'],
                    ]);
                    continue;
                  }
                }
                // Re-throw if not a lock timeout or max retries reached.
                throw $e;
              }
            } else {
              \Drupal::logger('misstraal_ai_contexts')->warning('Entity @type:@id not found or not a content entity', [
                '@type' => $entityContext['entity_type'],
                '@id' => $entityContext['entity_id'],
              ]);
              $saveSuccess = TRUE; // Entity not found, no need to retry.
            }
          }
          catch (DatabaseExceptionWrapper $e) {
            // Check if it's a lock timeout error (MySQL error 1205).
            if (strpos($e->getMessage(), '1205') !== FALSE || strpos($e->getMessage(), 'Lock wait timeout') !== FALSE) {
              if ($attempt < $maxRetries) {
                \Drupal::logger('misstraal_ai_contexts')->warning('Database lock timeout on attempt @attempt for entity @type:@id, retrying...', [
                  '@attempt' => $attempt,
                  '@type' => $entityContext['entity_type'],
                  '@id' => $entityContext['entity_id'],
                ]);
                continue;
              }
            }
            // Log and continue (don't throw) - this node is designed to continue even if save fails.
            \Drupal::logger('misstraal_ai_contexts')->error('Failed to save entity @type:@id after @attempts attempts: @error', [
              '@type' => $entityContext['entity_type'],
              '@id' => $entityContext['entity_id'],
              '@attempts' => $attempt,
              '@error' => $e->getMessage(),
            ]);
            break; // Exit retry loop.
          }
          catch (\Exception $e) {
            // For non-database exceptions, log and continue (don't throw).
            \Drupal::logger('misstraal_ai_contexts')->error('Failed to save entity @type:@id: @error', [
              '@type' => $entityContext['entity_type'],
              '@id' => $entityContext['entity_id'],
              '@error' => $e->getMessage(),
            ]);
            // Don't throw - continue and return the parsed scores even if save fails
            break; // Exit retry loop.
          }
        }
      }

      $output = [
        'ai_score' => $ai_score,
        'ai_reasoning' => $ai_reasoning,
        'editorial_score' => $editorial_score,
        'editorial_reasoning' => $editorial_reasoning,
        'raw_response' => $responseText,
      ];

      // Add entity context if available.
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
      \Drupal::logger('misstraal_ai_contexts')->error('Guideline score parser error: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Failed to parse scores: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'response' => [
          'type' => 'string',
          'title' => 'Response',
          'description' => 'Response from ChatModelNode',
          'required' => FALSE,
        ],
        'raw_response' => [
          'type' => 'string',
          'title' => 'Raw Response',
          'description' => 'Raw response from ChatModelNode',
          'required' => FALSE,
        ],
        'data' => [
          'type' => 'mixed',
          'title' => 'Data',
          'description' => 'Trigger data containing entity context (entity_id, entity_type, bundle)',
          'required' => FALSE,
        ],
        'entity_id' => [
          'type' => 'string',
          'title' => 'Entity ID',
          'description' => 'The entity ID to update',
          'required' => FALSE,
        ],
        'entity_type' => [
          'type' => 'string',
          'title' => 'Entity Type',
          'description' => 'The entity type (e.g., node)',
          'required' => FALSE,
        ],
        'bundle' => [
          'type' => 'string',
          'title' => 'Bundle',
          'description' => 'The entity bundle (e.g., article)',
          'required' => FALSE,
        ],
        'input' => [
          'type' => 'mixed',
          'title' => 'Input',
          'description' => 'Input from any previous node (unified input port)',
          'required' => FALSE,
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
        'raw_response' => [
          'type' => 'string',
          'title' => 'Raw Response',
          'description' => 'The cleaned JSON response',
        ],
      ],
    ];
  }

}
