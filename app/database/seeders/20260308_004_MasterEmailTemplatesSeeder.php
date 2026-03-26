<?php

use Core\Database\Schema\Seeder;

return new class extends Seeder
{
    protected string $table = 'master_email_templates';
    protected string $connection = 'default';

    public function run(): void
    {
        $appName = defined('APP_NAME') && is_string(APP_NAME) && APP_NAME !== ''
            ? APP_NAME
            : 'MythPHP';

        $templates = [
            [
                'id' => 1,
                'email_type' => 'SECURE_LOGIN',
                'email_subject' => sprintf('%s: Secure Login', $appName),
                'email_header' => null,
                'email_body' => str_replace('MythPHP', $appName, <<<'HTML'
Hi %name%,
<br><br>
Your account <b>%email%</b> was just used to sign in from <b>%browsers% on %os%</b>.
<br><br>
%details%
<br><br>
Don't recognise this activity?
<br>
Secure your account, from this link.
<br>
<a href="%url%"><b>Login.</b></a>
<br><br>
Why are we sending this?<br>We take security very seriously and we want to keep you in the loop on important actions in your account.
<br><br>
Sincerely,<br>
MythPHP
HTML),
                'email_footer' => null,
                'email_cc' => null,
                'email_bcc' => null,
                'email_status' => 1,
                'created_at' => '2025-03-09 15:23:29',
                'updated_at' => null,
            ],
            [
                'id' => 2,
                'email_type' => 'RESET_PASSWORD',
                'email_subject' => sprintf('%s: Reset Password', $appName),
                'email_header' => null,
                'email_body' => <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #007bff; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border: 1px solid #dee2e6; }
        .password-box { background: #fff; border: 2px solid #007bff; padding: 15px; margin: 20px 0; text-align: center; border-radius: 5px; }
        .password { font-size: 24px; font-weight: bold; color: #007bff; letter-spacing: 2px; }
        .footer { background: #6c757d; color: white; padding: 15px; text-align: center; border-radius: 0 0 5px 5px; font-size: 12px; }
        .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 5px; color: #856404; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>%app_name%</h1>
            <h2>Password Reset</h2>
        </div>

        <div class="content">
            <h3>Hello %user_fullname%,</h3>

            <p>Your password has been successfully reset as requested. Below is your new temporary password:</p>

            <div class="password-box">
                <div class="password">%new_password%</div>
            </div>

            <div class="warning">
                <strong>⚠️ Important Security Notice:</strong>
                <ul>
                    <li>Please change this password immediately after logging in</li>
                    <li>Do not share this password with anyone</li>
                    <li>Use a strong, unique password for better security</li>
                </ul>
            </div>

            <p><strong>Next Steps:</strong></p>
            <ol>
                <li>Log in to your account using the new password above</li>
                <li>Go to your profile or account settings</li>
                <li>Change your password to something secure and memorable</li>
            </ol>

            <p>If you did not request this password reset, please contact our support team immediately.</p>

            <p>Thank you for using %app_name%!</p>

            <p>Best regards,<br>
            The %app_name% Team</p>
        </div>

        <div class="footer">
            <p>This is an automated message. Please do not reply to this email.</p>
            <p>&copy; %current_year% %app_name%. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML,
                'email_footer' => null,
                'email_cc' => null,
                'email_bcc' => null,
                'email_status' => 1,
                'created_at' => '2025-03-09 15:23:29',
                'updated_at' => null,
            ],
        ];

        foreach ($templates as $template) {
            $this->insertOrUpdate($this->table, ['id' => $template['id']], $template);
        }
    }
};