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
 * Guideline Score to Entity Save node processor for FlowDrop workflows.
 *
 * Loads and saves the entity with guideline scores directly.
 */
#[FlowDropNodeProcessor(
  id: "guideline_score_to_entity_save",
  label: new TranslatableMarkup("Guideline Score to Entity Save"),
  description: "Loads and saves the entity with guideline scores",
  version: "2.0.0"
)]
class GuidelineScoreToEntitySaveNode extends AbstractFlowDropNodeProcessor {

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    return ValidationResult::success();
  }

  /**
   * Extracts data from unified input port or direct parameters.
   *
   * @param \Drupal\flowdrop\DTO\ParameterBagInterface $params
   *   The parameter bag.
   *
   * @return array
   *   Extracted data array.
   */
  protected function extractInputData(ParameterBagInterface $params): array {
    $data = [];
    
    // Log all available parameters for debugging
    $allParams = $params->all();
    \Drupal::logger('misstraal_ai_contexts')->debug('GuidelineScoreToEntitySave: All params keys: @keys', [
      '@keys' => json_encode(array_keys($allParams)),
    ]);
    
    // Log the actual input value from allParams if it exists
    if (isset($allParams['input'])) {
      $inputValue = $allParams['input'];
      \Drupal::logger('misstraal_ai_contexts')->debug('GuidelineScoreToEntitySave: Input from allParams - type: @type, value: @value', [
        '@type' => gettype($inputValue),
        '@value' => is_string($inputValue) ? substr($inputValue, 0, 500) : (is_array($inputValue) || is_object($inputValue) ? json_encode($inputValue) : (string) $inputValue),
      ]);
    }
    
    // Get individual parameters first (these are always available when connected)
    // Try both get() method and direct access from all()
    $individualData = [];
    foreach (['ai_score', 'ai_reasoning', 'editorial_score', 'editorial_reasoning', 'entity_id', 'entity_type', 'bundle'] as $key) {
      $value = $params->get($key, NULL);
      if ($value === NULL && isset($allParams[$key])) {
        $value = $allParams[$key];
      }
      $individualData[$key] = $value;
    }
    
    // Log raw individual parameter values
    \Drupal::logger('misstraal_ai_contexts')->debug('GuidelineScoreToEntitySave: Individual params raw: @params', [
      '@params' => json_encode($individualData),
    ]);
    
    // Priority 1: Use individual parameters if they have data (including 0 and empty strings for entity_id)
    foreach ($individualData as $key => $value) {
      // For entity_id, entity_type, and bundle, accept any non-NULL value (including empty string)
      if (in_array($key, ['entity_id', 'entity_type', 'bundle'])) {
        if ($value !== NULL) {
          $data[$key] = $value;
        }
      }
      // For other fields, only add if not NULL and not empty string
      elseif ($value !== NULL && $value !== '') {
        $data[$key] = $value;
      }
    }
    
    // Priority 2: Check unified 'input' parameter as fallback or supplement
    // Try both get() method and direct access from all()
    $unifiedInput = $params->get('input', NULL);
    if ($unifiedInput === NULL && isset($allParams['input'])) {
      $unifiedInput = $allParams['input'];
    }
    
    // Log raw input for debugging
    \Drupal::logger('misstraal_ai_contexts')->debug('GuidelineScoreToEntitySave: Raw input from get(): @get, from allParams: @all, type: @type', [
      '@get' => $params->get('input', NULL) !== NULL ? 'exists' : 'NULL',
      '@all' => isset($allParams['input']) ? 'exists' : 'not set',
      '@type' => $unifiedInput !== NULL ? gettype($unifiedInput) : 'NULL',
    ]);
    
    // Handle different input formats
    if ($unifiedInput !== NULL) {
      // Handle JSON string input
      if (is_string($unifiedInput) && !empty($unifiedInput)) {
        $decoded = json_decode($unifiedInput, TRUE);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
          $unifiedInput = $decoded;
        } else {
          \Drupal::logger('misstraal_ai_contexts')->warning('Failed to decode JSON input string: @error', [
            '@error' => json_last_error_msg(),
          ]);
        }
      }
      
      // Handle object (stdClass) - convert to array
      if (is_object($unifiedInput)) {
        $unifiedInput = (array) $unifiedInput;
      }
      
      if (is_array($unifiedInput) && !empty($unifiedInput)) {
        // Handle wrapped input: {"input": {"ai_score": 80, ...}}
        if (isset($unifiedInput['input']) && is_array($unifiedInput['input']) && !empty($unifiedInput['input'])) {
          // Merge input data, prioritizing the nested input
          foreach ($unifiedInput['input'] as $key => $value) {
            if ($value !== NULL && $value !== '') {
              $data[$key] = $value;
            }
          }
        }
        // Handle direct input: {"ai_score": 80, "entity_id": "1", ...}
        elseif (isset($unifiedInput['ai_score']) || isset($unifiedInput['entity_id']) || isset($unifiedInput['editorial_score'])) {
          // Merge input data, but don't overwrite existing individual parameters
          foreach ($unifiedInput as $key => $value) {
            if ($value !== NULL && $value !== '' && (!isset($data[$key]) || empty($data[$key]))) {
              $data[$key] = $value;
            }
          }
        }
      }
    }

    \Drupal::logger('misstraal_ai_contexts')->debug('GuidelineScoreToEntitySave: Individual params: @individual, Unified input type: @type, Unified input: @unified, Final extracted data: @data', [
      '@individual' => json_encode($individualData),
      '@type' => gettype($unifiedInput),
      '@unified' => is_array($unifiedInput) ? json_encode($unifiedInput) : (is_string($unifiedInput) ? substr($unifiedInput, 0, 200) : 'not array/string'),
      '@data' => json_encode($data),
    ]);

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $inputData = $this->extractInputData($params);

    // Extract values with proper type handling.
    $aiScore = isset($inputData['ai_score']) && $inputData['ai_score'] !== NULL && $inputData['ai_score'] !== ''
      ? (int) $inputData['ai_score'] 
      : 0;
    
    $aiReasoning = isset($inputData['ai_reasoning']) && $inputData['ai_reasoning'] !== NULL
      ? (string) $inputData['ai_reasoning']
      : '';
    
    $editorialScore = isset($inputData['editorial_score']) && $inputData['editorial_score'] !== NULL && $inputData['editorial_score'] !== ''
      ? (string) $inputData['editorial_score']
      : '0';
    
    $editorialReasoning = isset($inputData['editorial_reasoning']) && $inputData['editorial_reasoning'] !== NULL
      ? (string) $inputData['editorial_reasoning']
      : '';
    
    // Handle entity_id as string or integer
    $entityId = NULL;
    if (isset($inputData['entity_id'])) {
      $rawEntityId = $inputData['entity_id'];
      if ($rawEntityId !== NULL && $rawEntityId !== '') {
        $entityId = (string) $rawEntityId;
      }
    }
    
    $entityType = isset($inputData['entity_type']) && $inputData['entity_type'] !== NULL && $inputData['entity_type'] !== ''
      ? (string) $inputData['entity_type']
      : 'node';
    
    $bundle = isset($inputData['bundle']) && $inputData['bundle'] !== NULL && $inputData['bundle'] !== ''
      ? (string) $inputData['bundle']
      : NULL;

    // Validate required fields.
    if (empty($entityId)) {
      // Log all available parameters for debugging
      $allParams = $params->all();
      \Drupal::logger('misstraal_ai_contexts')->error('Entity ID is missing. Available params: @params, Extracted data: @data', [
        '@params' => json_encode(array_keys($allParams)),
        '@data' => json_encode($inputData),
      ]);
      throw new \RuntimeException('Entity ID is required to save the entity. Available parameters: ' . implode(', ', array_keys($allParams)));
    }

    if (empty($entityType)) {
      throw new \RuntimeException('Entity type is required to save the entity.');
    }

    // Log the extracted values for debugging.
    \Drupal::logger('misstraal_ai_contexts')->debug('GuidelineScoreToEntitySave: Saving entity - ai_score: @ai, editorial_score: @ed, entity_id: @eid, entity_type: @et, bundle: @b', [
      '@ai' => $aiScore,
      '@ed' => $editorialScore,
      '@eid' => $entityId,
      '@et' => $entityType,
      '@b' => $bundle ?? 'NULL',
    ]);

    // Retry logic for handling database lock timeouts.
    $maxRetries = 3;
    $retryDelay = 0.1; // 100ms initial delay
    $lastException = NULL;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
      try {
        $entityTypeManager = \Drupal::entityTypeManager();
        $storage = $entityTypeManager->getStorage($entityType);
        
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
          $storage->resetCache([$entityId]);
          usleep((int) ($retryDelay * 1000000 * $attempt));
        }

        // Use loadUnchanged() to load directly from database without triggering
        // cache writes. This reduces lock contention when multiple processes
        // load the same entity concurrently.
        $entity = $storage->loadUnchanged($entityId);

        if (!$entity) {
          throw new \RuntimeException("Entity of type '{$entityType}' with ID '{$entityId}' not found.");
        }

        // Ensure we have a content entity (nodes, etc.) that supports fields.
        if (!($entity instanceof ContentEntityInterface)) {
          throw new \RuntimeException("Entity of type '{$entityType}' with ID '{$entityId}' is not a content entity and cannot have fields set.");
        }

        // Log available fields for debugging
        $availableFields = array_keys($entity->getFieldDefinitions());
        \Drupal::logger('misstraal_ai_contexts')->debug('Available fields on entity @type:@id: @fields', [
          '@type' => $entityType,
          '@id' => $entityId,
          '@fields' => implode(', ', array_filter($availableFields, function($field) {
            return strpos($field, 'field_') === 0;
          })),
        ]);

        // Set field values.
        $fieldsSet = [];
        if ($entity->hasField('field_ai_score')) {
          $entity->set('field_ai_score', $aiScore);
          $fieldsSet[] = 'field_ai_score';
        }

        if ($entity->hasField('field_ai_reasoning') && !empty($aiReasoning)) {
          $entity->set('field_ai_reasoning', $aiReasoning);
          $fieldsSet[] = 'field_ai_reasoning';
        }

        if ($entity->hasField('field_editorial_score')) {
          $entity->set('field_editorial_score', $editorialScore);
          $fieldsSet[] = 'field_editorial_score';
        }

        if ($entity->hasField('field_editorial_description')) {
          $entity->set('field_editorial_description', $editorialReasoning);
          $fieldsSet[] = 'field_editorial_description';
        }

        \Drupal::logger('misstraal_ai_contexts')->debug('Setting fields on entity @type:@id: @fields', [
          '@type' => $entityType,
          '@id' => $entityId,
          '@fields' => implode(', ', $fieldsSet),
        ]);

        // Save the entity with retry logic for lock timeouts.
        try {
          $entity->save();
        }
        catch (DatabaseExceptionWrapper $e) {
          // Check if it's a lock timeout error (MySQL error 1205).
          if (strpos($e->getMessage(), '1205') !== FALSE || strpos($e->getMessage(), 'Lock wait timeout') !== FALSE) {
            if ($attempt < $maxRetries) {
              \Drupal::logger('misstraal_ai_contexts')->warning('Database lock timeout on attempt @attempt for entity @type:@id, retrying...', [
                '@attempt' => $attempt,
                '@type' => $entityType,
                '@id' => $entityId,
              ]);
              $lastException = $e;
              continue;
            }
          }
          // Re-throw if not a lock timeout or max retries reached.
          throw $e;
        }

        \Drupal::logger('misstraal_ai_contexts')->info('Successfully saved guideline scores to entity @type:@id', [
          '@type' => $entityType,
          '@id' => $entityId,
        ]);

        // Return output similar to EntitySave node for consistency.
        $output = [
          'entity_id' => (string) $entity->id(),
          'entity_type' => $entityType,
          'bundle' => $entity->bundle(),
          'is_new' => FALSE,
          'label' => $entity->label(),
          'uuid' => $entity->uuid(),
        ];
        
        // Add timestamp fields if available (most content entities have these).
        if ($entity->hasField('created') && !$entity->get('created')->isEmpty()) {
          $output['created'] = (int) $entity->get('created')->value;
        }
        
        if ($entity->hasField('changed') && !$entity->get('changed')->isEmpty()) {
          $output['changed'] = (int) $entity->get('changed')->value;
        }
        
        return $output;
      }
      catch (DatabaseExceptionWrapper $e) {
        // Check if it's a lock timeout error (MySQL error 1205).
        if (strpos($e->getMessage(), '1205') !== FALSE || strpos($e->getMessage(), 'Lock wait timeout') !== FALSE) {
          if ($attempt < $maxRetries) {
            \Drupal::logger('misstraal_ai_contexts')->warning('Database lock timeout on attempt @attempt for entity @type:@id, retrying...', [
              '@attempt' => $attempt,
              '@type' => $entityType,
              '@id' => $entityId,
            ]);
            $lastException = $e;
            continue;
          }
        }
        // Re-throw if not a lock timeout or max retries reached.
        \Drupal::logger('misstraal_ai_contexts')->error('Failed to save entity: @error', [
          '@error' => $e->getMessage(),
        ]);
        throw new \RuntimeException('Failed to save entity: ' . $e->getMessage(), 0, $e);
      }
      catch (\Exception $e) {
        // For non-database exceptions, don't retry.
        \Drupal::logger('misstraal_ai_contexts')->error('Failed to save entity: @error', [
          '@error' => $e->getMessage(),
        ]);
        throw new \RuntimeException('Failed to save entity: ' . $e->getMessage(), 0, $e);
      }
    }

    // If we've exhausted all retries, throw the last exception.
    if ($lastException) {
      \Drupal::logger('misstraal_ai_contexts')->error('Failed to save entity after @attempts attempts: @error', [
        '@attempts' => $maxRetries,
        '@error' => $lastException->getMessage(),
      ]);
      throw new \RuntimeException('Failed to save entity after ' . $maxRetries . ' attempts: ' . $lastException->getMessage(), 0, $lastException);
    }
    
    // This should never be reached, but provide a fallback for code path completeness.
    throw new \RuntimeException('Failed to save entity: Unknown error occurred after ' . $maxRetries . ' attempts.');
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'input' => [
          'type' => 'mixed',
          'title' => 'Input',
          'description' => 'Input from GuidelineScoreParserNode (unified input port)',
          'required' => FALSE,
        ],
        'ai_score' => [
          'type' => 'integer',
          'title' => 'AI Score',
          'description' => 'AI score (0-100)',
          'required' => FALSE,
        ],
        'ai_reasoning' => [
          'type' => 'string',
          'title' => 'AI Reasoning',
          'description' => 'AI reasoning text',
          'required' => FALSE,
        ],
        'editorial_score' => [
          'type' => 'string',
          'title' => 'Editorial Score',
          'description' => 'Editorial score (0-100)',
          'required' => FALSE,
        ],
        'editorial_reasoning' => [
          'type' => 'string',
          'title' => 'Editorial Reasoning',
          'description' => 'Editorial reasoning text',
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
        'entity_id' => [
          'type' => 'string',
          'title' => 'Entity ID',
          'description' => 'The saved entity ID',
        ],
        'entity_type' => [
          'type' => 'string',
          'title' => 'Entity Type',
          'description' => 'The entity type that was saved',
        ],
        'bundle' => [
          'type' => 'string',
          'title' => 'Bundle',
          'description' => 'The entity bundle that was saved',
        ],
        'is_new' => [
          'type' => 'boolean',
          'title' => 'Is New',
          'description' => 'Whether the entity was newly created (always false for updates)',
        ],
        'label' => [
          'type' => 'string',
          'title' => 'Label',
          'description' => 'The entity label/title',
        ],
        'uuid' => [
          'type' => 'string',
          'title' => 'UUID',
          'description' => 'The entity UUID',
        ],
        'created' => [
          'type' => 'integer',
          'title' => 'Created',
          'description' => 'The entity creation timestamp',
        ],
        'changed' => [
          'type' => 'integer',
          'title' => 'Changed',
          'description' => 'The entity last changed timestamp',
        ],
      ],
    ];
  }

}
