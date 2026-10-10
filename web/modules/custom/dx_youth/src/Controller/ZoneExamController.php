<?php

namespace Drupal\dx_youth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Zone exam controller for education section exams.
 */
class ZoneExamController extends ControllerBase {

  /**
   * Zone labels mapping.
   */
  protected const ZONE_LABELS = [
    'student' => '学生',
    'frontier' => '前沿学科',
  ];

  /**
   * Tier labels mapping.
   */
  protected const TIER_LABELS = [
    'k12' => '中小学',
    'highschool' => '高中',
    'college' => '大学',
    'bci' => '脑电波',
    'autocar' => '智能无人车',
    'drone' => '无人机',
    'universe' => '宇宙学',
  ];

  /**
   * Exam list page - shows available exams by zone.
   */
  public function examList(string $zone = ''): array {
    $db = \Drupal::database();
    $current_user = $this->currentUser();
    $uid = $current_user->id();

    // Get exams filtered by zone if specified
    $query = $db->select('dx_youth_zone_exam', 'e')
      ->fields('e')
      ->condition('status', 'published')
      ->orderBy('created_at', 'DESC');

    if ($zone) {
      $query->condition('zone', $zone);
    }

    $exams = $query->execute()->fetchAllAssoc('id');

    // Get user's attempt history
    $attempts = [];
    if ($uid && $uid > 1) {
      $attempt_query = $db->select('dx_youth_zone_exam_attempt', 'a')
        ->fields('a')
        ->condition('uid', $uid)
        ->orderBy('finished_at', 'DESC');
      $attempt_rows = $attempt_query->execute()->fetchAll();
      foreach ($attempt_rows as $row) {
        $attempts[$row->exam_id][] = $row;
      }
    }

    // Group exams by zone and tier
    $grouped = [];
    foreach ($exams as $exam) {
      $zone_key = $exam->zone ?: 'general';
      $tier_key = $exam->tier ?: 'all';
      $grouped[$zone_key][$tier_key][] = $exam;
    }

    // Build exam cards
    $exam_cards = [];
    foreach ($exams as $exam) {
      $user_attempts = $attempts[$exam->id] ?? [];
      $best_score = 0;
      $attempt_count = count($user_attempts);
      foreach ($user_attempts as $att) {
        if ($att->score > $best_score) {
          $best_score = $att->score;
        }
      }

      $exam_cards[] = [
        'id' => $exam->id,
        'title' => $exam->title,
        'description' => $exam->description,
        'zone' => $exam->zone,
        'zone_label' => self::ZONE_LABELS[$exam->zone] ?? '综合',
        'tier' => $exam->tier,
        'tier_label' => self::TIER_LABELS[$exam->tier] ?? '全部',
        'question_count' => $exam->question_count,
        'time_limit' => $exam->time_limit,
        'pass_score' => $exam->pass_score,
        'total_score' => $exam->total_score,
        'attempt_count' => $attempt_count,
        'best_score' => $best_score,
        'url' => Url::fromRoute('dx_youth.zone_exam_take', ['exam_id' => $exam->id])->toString(),
      ];
    }

    return [
      '#theme' => 'dx_youth_zone_exam_list',
      '#exams' => $exam_cards,
      '#grouped' => $grouped,
      '#current_zone' => $zone,
      '#zone_labels' => self::ZONE_LABELS,
      '#logged_in' => $current_user->isAuthenticated(),
      '#attached' => ['library' => ['dx_youth/classroom']],
      '#cache' => ['max-age' => 0, 'contexts' => ['user']],
    ];
  }

  /**
   * Take exam page - shows questions and collects answers.
   */
  public function takeExam(int $exam_id): array {
    $current_user = $this->currentUser();
    $anonymous_allowed = (bool) \Drupal::config('dx_youth.settings')->get('exam_anonymous_access');
    if (!$current_user->isAuthenticated() && !$anonymous_allowed) {
      throw new AccessDeniedHttpException('Please login to take exam.');
    }

    $db = \Drupal::database();
    $exam = $db->select('dx_youth_zone_exam', 'e')
      ->fields('e')
      ->condition('id', $exam_id)
      ->condition('status', 'published')
      ->execute()
      ->fetchObject();

    if (!$exam) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    // Get questions
    $question_ids = json_decode($exam->question_ids, TRUE) ?: [];
    $questions = [];
    if (!empty($question_ids)) {
      $questions = $db->select('dx_youth_question_bank', 'q')
        ->fields('q')
        ->condition('id', $question_ids, 'IN')
        ->condition('status', 'published')
        ->execute()
        ->fetchAllAssoc('id');

      // Maintain order from question_ids
      $ordered = [];
      foreach ($question_ids as $qid) {
        if (isset($questions[$qid])) {
          $ordered[$qid] = $questions[$qid];
        }
      }
      $questions = $ordered;
    }

    // Format questions for template
    $formatted_questions = [];
    foreach ($questions as $q) {
      $options = json_decode($q->options, TRUE) ?: [];
      $formatted_questions[] = [
        'id' => $q->id,
        'type' => $q->type,
        'question' => $q->question_html ?: $q->question,
        'options' => $options,
        'score' => $q->score ?: 10,
      ];
    }

    return [
      '#theme' => 'dx_youth_zone_exam_take',
      '#exam' => [
        'id' => $exam->id,
        'title' => $exam->title,
        'description' => $exam->description,
        'question_count' => count($formatted_questions),
        'time_limit' => $exam->time_limit,
        'pass_score' => $exam->pass_score,
        'total_score' => $exam->total_score,
        'zone_label' => self::ZONE_LABELS[$exam->zone] ?? '综合',
        'tier_label' => self::TIER_LABELS[$exam->tier] ?? '全部',
      ],
      '#questions' => $formatted_questions,
      '#submit_endpoint' => Url::fromRoute('dx_youth.zone_exam_submit', ['exam_id' => $exam_id])->toString(),
      '#attached' => [
        'library' => ['dx_youth/classroom'],
        'drupalSettings' => [
          'dxYouth' => [
            'examId' => $exam->id,
            'timeLimit' => $exam->time_limit * 60,
            'csrfToken' => \Drupal::csrfToken()->get('x-csrf-token'),
          ],
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * API: Submit exam answers and calculate score.
   */
  public function submitExam(int $exam_id, Request $request): JsonResponse {
    $current_user = $this->currentUser();
    $anonymous_allowed = (bool) \Drupal::config('dx_youth.settings')->get('exam_anonymous_access');
    if (!$current_user->isAuthenticated() && !$anonymous_allowed) {
      return new JsonResponse(['ok' => FALSE, 'message' => 'Please login.'], 403);
    }

    $uid = $current_user->id();
    $db = \Drupal::database();

    // Get exam
    $exam = $db->select('dx_youth_zone_exam', 'e')
      ->fields('e')
      ->condition('id', $exam_id)
      ->execute()
      ->fetchObject();

    if (!$exam) {
      return new JsonResponse(['ok' => FALSE, 'message' => 'Exam not found.'], 404);
    }

    // Parse answers from request
    $data = json_decode($request->getContent(), TRUE);
    $user_answers = $data['answers'] ?? [];
    $time_spent = $data['time_spent'] ?? 0;

    // Get correct answers
    $question_ids = json_decode($exam->question_ids, TRUE) ?: [];
    $questions = $db->select('dx_youth_question_bank', 'q')
      ->fields('q')
      ->condition('id', $question_ids, 'IN')
      ->execute()
      ->fetchAllAssoc('id');

    // Calculate score
    $score = 0;
    $correct_count = 0;
    $total_questions = count($questions);
    $results = [];

    foreach ($questions as $qid => $q) {
      $user_answer = $user_answers[$qid] ?? '';
      $correct_answer = strtolower(trim($q->answer));
      $is_correct = FALSE;

      // Check answer based on question type
      switch ($q->type) {
        case 'choice':
        case 'judge':
          $is_correct = strtolower(trim($user_answer)) === $correct_answer;
          break;

        case 'multiple':
          // Multiple choice - compare sorted arrays
          $user_arr = array_filter(array_map('trim', explode(',', $user_answer)));
          $correct_arr = array_filter(array_map('trim', explode(',', $correct_answer)));
          sort($user_arr);
          sort($correct_arr);
          $is_correct = $user_arr === $correct_arr;
          break;

        case 'fill':
          // Fill in blank - case insensitive, trim whitespace
          $is_correct = strtolower(trim($user_answer)) === strtolower($correct_answer);
          break;

        default:
          // Essay type - needs manual grading
          $is_correct = FALSE;
      }

      if ($is_correct) {
        $score += $q->score ?: 10;
        $correct_count++;
      }

      $results[$qid] = [
        'correct' => $is_correct,
        'user_answer' => $user_answer,
        'correct_answer' => $q->answer,
        'explanation' => $q->explanation,
      ];
    }

    // Get member_id
    $member_id = $db->select('dx_youth_member', 'm')
      ->fields('m', ['member_id'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField() ?: 0;

    // Save attempt
    $attempt_id = $db->insert('dx_youth_zone_exam_attempt')
      ->fields([
        'exam_id' => $exam_id,
        'uid' => $uid,
        'member_id' => $member_id,
        'answers' => json_encode($user_answers, JSON_UNESCAPED_UNICODE),
        'score' => $score,
        'total_score' => $exam->total_score,
        'correct_count' => $correct_count,
        'time_spent' => $time_spent,
        'passed' => $score >= $exam->pass_score ? 1 : 0,
        'started_at' => \Drupal::time()->getRequestTime() - $time_spent,
        'finished_at' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    return new JsonResponse([
      'ok' => TRUE,
      'attempt_id' => $attempt_id,
      'score' => $score,
      'total_score' => $exam->total_score,
      'correct_count' => $correct_count,
      'total_questions' => $total_questions,
      'passed' => $score >= $exam->pass_score,
      'pass_score' => $exam->pass_score,
      'results' => $results,
      'redirect' => Url::fromRoute('dx_youth.zone_exam_result', [
        'exam_id' => $exam_id,
        'attempt_id' => $attempt_id,
      ])->toString(),
    ]);
  }

  /**
   * Exam result page.
   */
  public function examResult(int $exam_id, int $attempt_id): array {
    $current_user = $this->currentUser();
    $uid = (int) $current_user->id();

    $db = \Drupal::database();

    // Get attempt — for authenticated users, restrict to own attempts;
    // for anonymous users (uid=0), allow viewing by attempt_id only.
    $query = $db->select('dx_youth_zone_exam_attempt', 'a')
      ->fields('a')
      ->condition('id', $attempt_id);
    if ($uid > 0) {
      $query->condition('uid', $uid);
    }
    $attempt = $query->execute()->fetchObject();

    if (!$attempt) {
      throw new AccessDeniedHttpException('Attempt not found.');
    }

    // Get exam
    $exam = $db->select('dx_youth_zone_exam', 'e')
      ->fields('e')
      ->condition('id', $exam_id)
      ->execute()
      ->fetchObject();

    // Get questions with answers
    $question_ids = json_decode($exam->question_ids, TRUE) ?: [];
    $questions = $db->select('dx_youth_question_bank', 'q')
      ->fields('q')
      ->condition('id', $question_ids, 'IN')
      ->execute()
      ->fetchAllAssoc('id');

    $user_answers = json_decode($attempt->answers, TRUE) ?: [];

    // Build question review
    $review = [];
    foreach ($questions as $qid => $q) {
      $user_answer = $user_answers[$qid] ?? '';
      $options = json_decode($q->options, TRUE) ?: [];

      $is_correct = FALSE;
      switch ($q->type) {
        case 'choice':
        case 'judge':
          $is_correct = strtolower(trim($user_answer)) === strtolower(trim($q->answer));
          break;
        case 'multiple':
          $user_arr = array_filter(array_map('trim', explode(',', $user_answer)));
          $correct_arr = array_filter(array_map('trim', explode(',', $q->answer)));
          sort($user_arr);
          sort($correct_arr);
          $is_correct = $user_arr === $correct_arr;
          break;
        case 'fill':
          $is_correct = strtolower(trim($user_answer)) === strtolower(trim($q->answer));
          break;
      }

      $review[] = [
        'id' => $q->id,
        'type' => $q->type,
        'question' => $q->question_html ?: $q->question,
        'options' => $options,
        'user_answer' => $user_answer,
        'correct_answer' => $q->answer,
        'explanation' => $q->explanation,
        'is_correct' => $is_correct,
        'score' => $q->score ?: 10,
      ];
    }

    return [
      '#theme' => 'dx_youth_zone_exam_result',
      '#exam' => [
        'id' => $exam->id,
        'title' => $exam->title,
        'zone_label' => self::ZONE_LABELS[$exam->zone] ?? '综合',
        'tier_label' => self::TIER_LABELS[$exam->tier] ?? '全部',
      ],
      '#attempt' => [
        'score' => $attempt->score,
        'total_score' => $attempt->total_score,
        'correct_count' => $attempt->correct_count,
        'total_questions' => count($questions),
        'time_spent' => $attempt->time_spent,
        'passed' => $attempt->passed,
        'pass_score' => $exam->pass_score,
      ],
      '#review' => $review,
      '#attached' => ['library' => ['dx_youth/classroom']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Student results page — shows all exam attempts for the current user.
   */
  public function studentResults(): array {
    $current_user = $this->currentUser();
    $uid = (int) $current_user->id();

    $db = \Drupal::database();
    $results = [];

    if ($uid > 0) {
      // Get all attempts for this user, joined with exam info.
      $query = $db->select('dx_youth_zone_exam_attempt', 'a')
        ->fields('a')
        ->condition('a.uid', $uid)
        ->orderBy('a.finished_at', 'DESC');
      $attempt_rows = $query->execute()->fetchAll();

      foreach ($attempt_rows as $row) {
        $exam = $db->select('dx_youth_zone_exam', 'e')
          ->fields('e', ['id', 'title', 'zone', 'tier'])
          ->condition('id', $row->exam_id)
          ->execute()
          ->fetchObject();

        if ($exam) {
          $results[] = [
            'exam_title' => $exam->title,
            'tier' => self::TIER_LABELS[$exam->tier] ?? '全部',
            'finished_at' => date('Y-m-d H:i', $row->finished_at),
            'score' => $row->score,
            'total_score' => $row->total_score,
            'passed' => (bool) $row->passed,
            'detail_url' => Url::fromRoute('dx_youth.zone_exam_result', [
              'exam_id' => $row->exam_id,
              'attempt_id' => $row->id,
            ])->toString(),
          ];
        }
      }
    }

    return [
      '#theme' => 'dx_youth_student_results',
      '#results' => $results,
      '#exam_list_url' => Url::fromRoute('dx_youth.zone_exam_list')->toString(),
      '#attached' => ['library' => ['dx_youth/classroom']],
      '#cache' => ['max-age' => 0, 'contexts' => ['user']],
    ];
  }

}
