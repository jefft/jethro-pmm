#!/bin/bash -x

cd /tmp
# Instructions from https://github.com/tbar0970/jethro-pmm/pull/1398
curl -sLOJ 'https://easyjethro.com.au/demo/jethro_demodata.gz'
gunzip -f jethro_demodata.gz
mv jethro_demodata jethro_demodata.sql
atl_mysql < jethro_demodata.sql
cd -

# Set 'demo' account's password
password="qfntt7eYuwHs123"
echo -n "$password" \
	| php -r 'echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT);' \
	| xargs -I{} atl_mysql -e "UPDATE staff_member SET password='{}' WHERE username='demo';"

atl_mysql -e "select * from staff_member;"
# Configure regression tester with our local instance
#echo "{
#    base_url: \"${ATL_BASEURL_INTERNAL}\",
#    username: \"demo\",
#    password: \"$password\"
#}" > tests/regression/credentials.json
