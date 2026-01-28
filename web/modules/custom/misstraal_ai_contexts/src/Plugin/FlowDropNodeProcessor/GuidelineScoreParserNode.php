<?php

declare(strict_types=1);

namespace Drupal\misstraal_ai_contexts\Plugin\FlowDropNodeProcessor;

use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Guideline Score Parser node processor for FlowDrop workflows.
 *
 * Parses JSON response from ChatModelNode and extracts scores and reasoning.
 */
#[FlowDropNodeProcessor(
  id: "guideline_score_parser",
  label: new TranslatableMarkup("Guideline Score Parser"),
  description: "Parses JSON response from guideline scoring and extracts scores and reasoning",
  version: "1.0.0"
)]
class GuidelineScoreParserNode extends AbstractFlowDropNodeProcessor {

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    return ValidationResult::success();
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

      // Parse JSON response.
      $parsed = json_decode($responseText, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        // Try one more time with more aggressive stripping
        $responseText = preg_replace('/```[a-z]*\s*/i', '', $original_text);
        $responseText = preg_replace('/\s*```/i', '', $responseText);
        $responseText = str_replace('\\n', "\n", $responseText);
        $responseText = trim($responseText);
        $parsed = json_decode($responseText, TRUE);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
          \Drupal::logger('misstraal_ai_contexts')->error('Failed to parse JSON response: @error. Original (first 1000 chars): @original. Cleaned (first 1000 chars): @cleaned', [
            '@error' => json_last_error_msg(),
            '@original' => substr($original_text, 0, 1000),
            '@cleaned' => substr($responseText, 0, 1000),
          ]);
          throw new \RuntimeException('Failed to parse JSON response: ' . json_last_error_msg());
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

      return [
        'ai_score' => $ai_score,
        'ai_reasoning' => $ai_reasoning,
        'editorial_score' => $editorial_score,
        'editorial_reasoning' => $editorial_reasoning,
        'raw_response' => $responseText,
      ];
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
        'raw_response' => [
          'type' => 'string',
          'title' => 'Raw Response',
          'description' => 'The cleaned JSON response',
        ],
      ],
    ];
  }

}
