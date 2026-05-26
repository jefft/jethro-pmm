ALTER TABLE setting MODIFY `type` varchar(512);

--
UPDATE service_component SET content_html='<p>%SERVICE_BIBLE_READ_3_CONTENT%</p>',show_in_handout='full' where title='Bible Reading 3';
UPDATE service_component SET content_html='<p>%SERVICE_BIBLE_READ_4_CONTENT%</p>', show_in_handout='full' where title='Bible Reading 4';

SET @smsrank = (SELECT `rank` FROM setting WHERE symbol = 'SMS_SEND_LOGFILE');
--
VALUES
('SMS_UNICODE_PERMITTED', 'select{"when_free":"When it costs nothing extra","true":"Permitted (may cost extra)","false":"Not permitted"}', 'when_free', 'Whether to permit emojis and other unicode in SMSes', @smsrank+1);
