-- Small anonymized representation of the EasyMail 2.01.004 tables.
CREATE TABLE forms (
 id INTEGER PRIMARY KEY, name TEXT, url TEXT, theme_name TEXT, view_flag INTEGER,
 save_data INTEGER, admin_email TEXT, mail_title TEXT, conf_mail_flag INTEGER,
 include_submissions INTEGER, include_submissions_admin_email INTEGER, denied_ip TEXT,
 google_re_captcha_secret_key TEXT, thanks_message TEXT
);
CREATE TABLE form_blocks (
 id INTEGER PRIMARY KEY, form_type TEXT, name TEXT, title TEXT, html_id TEXT, restriction TEXT
);
CREATE TABLE form_items (
 id INTEGER PRIMARY KEY, form_id TEXT, form_block_id TEXT, view_no INTEGER, required TEXT,
 real_time_validation TEXT, re_enter_html_id TEXT
);
CREATE TABLE choices (
 id INTEGER PRIMARY KEY, block_id INTEGER, view_no INTEGER, choice_type TEXT, label_text TEXT, delete_flag INTEGER, image TEXT
);
CREATE TABLE histories (
 id INTEGER PRIMARY KEY, form_id INTEGER, form_name TEXT, admin_email TEXT, user_email TEXT,
 mail_title TEXT, content TEXT, attach_files TEXT, created_at TEXT, updated_at TEXT
);
CREATE TABLE plugins (id INTEGER PRIMARY KEY, uid TEXT, name TEXT, version TEXT, active_flag INTEGER);
INSERT INTO forms VALUES
 (5, '問合せ', NULL, 'gray', 1, 1, 'admin@example.org', 'お申込み #YYYY#', 1, 1, 1, '', NULL, '#email# 様');
INSERT INTO form_blocks VALUES
 (8, 'email', 'email', 'メール', 'mail', 'email'),
 (9, 'checkbox', 'options', '質問', 'options', ''),
 (13, 'file', 'document', '資料', 'document', '');
INSERT INTO form_items VALUES
 (101, '5', '8', 2, '1', '0', ''),
 (102, '5', '9', 1, '0', '0', ''),
 (103, '5', '13', 3, '0', '0', '');
INSERT INTO choices VALUES
 (11, 9, 2, 'checkbox', '福岡', 0, NULL),
 (12, 9, 1, 'checkbox', '東京', 0, NULL);
INSERT INTO histories VALUES
 (80, 5, '申込', 'admin@example.org', 'visitor@example.org', '受付済', '[{"form_type":"email","title":"メール","value":"visitor@example.org"},{"form_type":"checkbox","title":"質問","value":["東京","福岡"]},{"form_type":"text","title":"廃止済み","value":"0"}]', '', '2023-03-04 15:16:17','2023-03-04 15:16:17');
