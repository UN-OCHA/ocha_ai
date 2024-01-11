<?php

namespace Drupal\ocha_ai_chat\Helpers;

/**
 * Helper to manipulate vectors.
 */
class VectorHelper {

  /**
   * Calculate the dot product of 2 vectors.
   *
   * @param array $a
   *   First vector.
   * @param array $b
   *   Second vector.
   *
   * @return float
   *   Dot product of the 2 vectors.
   */
  public static function dotProdcut(array $a, array $b): float {
    return array_sum(array_map(function ($x, $y) {
       return $x * $y;
    }, $a, $b));
  }

  /**
   * Calculate the cosine similarity of 2 vectors.
   *
   * @param array $a
   *   First vector.
   * @param array $b
   *   Second vector.
   *
   * @return float
   *   Cosine similarity of the 2 vectors.
   */
  public static function cosineSimilarity(array $a, array $b) {
    return static::dotProdcut($a, $b) / sqrt(static::dotProdcut($a, $a) * static::dotProdcut($b, $b));
  }

  /**
   * Calculate the mean of an array of vectors along the given axis.
   *
   * @param array $vectors
   *   List of vectors.
   * @param string $axis
   *   Either `x` to calculate the means of the rows or `y` for the means of the
   *   columns.
   *
   * @return array
   *   Mean vector.
   */
  public static function mean(array $vectors, string $axis = 'y'): array {
    $x_count = count(reset($vectors));
    $y_count = count($vectors);

    $result = [];
    if ($axis === 'x') {
      for ($i = 0; $i < $y_count; $i++) {
        $result[$i] = array_sum($vectors[$i]) / $x_count;
      }
    }
    else {
      for ($i = 0; $i < $x_count; $i++) {
        $result[$i] = array_sum(array_column($vectors, $i)) / $y_count;
      }
    }
    return $result;
  }

  /**
   * Calculate the similarity score cut-off.
   *
   * The formula is `cutoff = mean + alpha * standard_deviation` where alpha
   * is a float between 0 and 1.
   *
   * @param array $similarity_scores
   *   List of similarity scores as floats.
   * @param float|null $alpha
   *   Coefficient to adjust the cut-off value.
   *
   * @return float
   *   Similarity score cut-off.
   *
   * @todo maybe this shoudl
   */
  public static function getSimilarityScoreCutOff(array $similarity_scores, float $alpha = 0.0): float {
    $count = count($similarity_scores);
    if ($count === 0) {
      return 0.0;
    }
    elseif ($count === 1) {
      return reset($similarity_scores);
    }

    // Determine the average similarity score.
    $mean = array_sum($similarity_scores) / $count;

    // Determine the standard deviation.
    $sample = FALSE;
    $variance = 0.0;
    foreach ($similarity_scores as $value) {
      $variance += pow((float) $value - $mean, 2);
    };
    $deviation = (float) sqrt($variance / ($sample ? $count - 1 : $count));

    // Calculate the similarity cut-off.
    $cutoff = $mean + $alpha * $deviation;

    // The above formula can result in a cutoff higher than the highest
    // similarity. In that case we return the max similarity to avoid discarding
    // everything.
    return min($cutoff, max($similarity_scores));
  }

}
