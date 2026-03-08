<?php

namespace App\Service;

use Symfony\Component\Console\Output\OutputInterface;

class EmailService {

  private $output;

  public function __construct(OutputInterface $output) {
    $this->output = $output;
  }

  /**
   * Send a plain-text email using PHP's mail().
   *
   * @param array $to Recipient email addresses.
   * @param string $subject The email subject.
   * @param string $body The plain-text body of the email.
   *
   * @return bool True if mail() returned true, false otherwise.
   */
  public function send(array $to, string $subject, string $body): bool {
    $to_str = implode(', ', $to);
    $headers = [
      'From' => 'Website Backup <noreply@' . (gethostname() ?: 'localhost') . '>',
      'X-Mailer' => 'PHP/' . phpversion(),
      'Content-Type' => 'text/plain; charset=UTF-8',
    ];

    try {
      // PHP 7.4+ supports array of headers for mail()
      $success = mail($to_str, $subject, $body, $headers);
      if (!$success) {
        $this->output->writeln('<warning>Failed to send email notification to: ' . $to_str . '</warning>');
      }

      return $success;
    }
    catch (\Exception $e) {
      $this->output->writeln('<warning>An error occurred while sending email: ' . $e->getMessage() . '</warning>');

      return FALSE;
    }
  }
}
