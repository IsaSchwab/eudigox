<?php
/**
 * ScoreCalculator — calcula o score de triagem da SXF.
 * 
 * Regras (do documento de requisitos):
 *   - Cada indicador tem peso por sexo (weight_male / weight_female).
 *   - Score = soma dos pesos dos indicadores respondidos como "yes".
 *   - "unknown" não soma. "no" não soma.
 *   - Limiar: 0,56 para homens / 0,55 para mulheres.
 *   - Score >= limiar → encaminhar para exame molecular.
 * 
 * Prioridade (heurística simples — pode ser refinada depois):
 *   - score >= threshold       → high
 *   - score >= threshold * 0.7 → medium
 *   - caso contrário           → low
 */

require_once __DIR__ . '/../config/database.php';

class ScoreCalculator
{
    /**
     * Calcula score, prioridade e recomendação.
     * 
     * @param string $sex     'M' ou 'F'
     * @param array  $answers [['indicator_id' => X, 'answer' => 'yes'|'no'|'unknown'], ...]
     * @return array { score, threshold, priority, recommendation }
     */
    public static function calculate(string $sex, array $answers): array
    {
        $pdo = Database::getConnection();

        // Pega pesos de todos indicadores ativos
        $stmt = $pdo->query("
            SELECT id, weight_male, weight_female, applies_to
            FROM indicators
            WHERE is_active = 1
        ");
        $indicators = [];
        foreach ($stmt as $row) {
            $indicators[(int)$row['id']] = $row;
        }

        $weightField = ($sex === 'M') ? 'weight_male' : 'weight_female';
        $score = 0.0;

        foreach ($answers as $a) {
            $id     = (int)($a['indicator_id'] ?? 0);
            $answer = $a['answer'] ?? null;

            if (!isset($indicators[$id]))      continue;
            if ($answer !== 'yes')             continue;

            $ind = $indicators[$id];

            // Indicador sexo-específico não conta para o sexo oposto
            if ($ind['applies_to'] !== 'both' && $ind['applies_to'] !== $sex) continue;

            $score += (float)$ind[$weightField];
        }

        $score     = round($score, 4);
        $threshold = ($sex === 'M') ? SCORE_THRESHOLD_MALE : SCORE_THRESHOLD_FEMALE;

        if ($score >= $threshold) {
            $priority       = 'high';
            $recommendation = 'refer_molecular';
        } elseif ($score >= $threshold * 0.7) {
            $priority       = 'medium';
            $recommendation = 'monitor';
        } else {
            $priority       = 'low';
            $recommendation = 'no_action';
        }

        return [
            'score'          => $score,
            'threshold'      => $threshold,
            'priority'       => $priority,
            'recommendation' => $recommendation,
        ];
    }
}
