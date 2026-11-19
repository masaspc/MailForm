-- Extend the fictional base dump on MariaDB. The sequence tables generate 60 definitions and 60 history-only forms.
CREATE TABLE migrations (migration VARCHAR(255));
INSERT INTO migrations VALUES ('2020_02_20_174440_create_forms_table');
CREATE TABLE cache (`key` VARCHAR(255), value TEXT, expiration INTEGER);
UPDATE forms SET theme_name = 'garden_example' WHERE id = 5;
UPDATE histories SET attach_files = CONCAT(REPEAT('a', 40), '.txt') WHERE id = 80;

INSERT INTO forms (id, name, url, theme_name, view_flag, save_data, admin_email, conf_mail_flag,
                   include_submissions, include_submissions_admin_email, thanks_message)
SELECT 5 + seq, CONCAT('展示会 ', seq), CONCAT('garden-', seq),
       CASE WHEN seq = 59 THEN 'gray' ELSE 'garden_example' END, 1, 1, 'staff@example.test', 1, 1, 1,
       CASE WHEN seq = 1 THEN '<table><tr><td>連絡先</td></tr></table>'
            WHEN seq = 2 THEN '#spt#'
            WHEN seq BETWEEN 4 AND 58 THEN '<strong>受付しました</strong>'
            ELSE '受付しました' END
FROM seq_1_to_59;
INSERT INTO form_items (id, form_id, form_block_id, view_no, required, real_time_validation, re_enter_html_id)
SELECT 1000 + seq, 5 + seq, '8', 1, '1', '0', '' FROM seq_1_to_59;
INSERT INTO histories (id, form_id, form_name, content, created_at, updated_at)
SELECT 1000 + seq, 5 + seq, CONCAT('展示会 ', seq),
       CASE WHEN seq = 3 THEN '旧形式の受付' ELSE '[]' END, '2022-08-01 09:00:00', '2022-08-01 09:00:00'
FROM seq_1_to_59;
INSERT INTO histories (id, form_id, form_name, content, created_at, updated_at)
SELECT 2000 + seq, 200 + seq, CONCAT('過去の展示会 ', seq), '[]', '2021-09-01 10:00:00', '2021-09-01 10:00:00'
FROM seq_1_to_60;
