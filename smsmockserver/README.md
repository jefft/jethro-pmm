SMSMockServer is technically an independent PHP webapp that mimics the APIs of SMS providers (currently 5CentSMS and Cellcast). It is used for functional testing the Jethro SMS implementation.

SMSMockServer is mounted at:

 - http://localhost:8080/smsmockserver/ in the demo instance (see ../DEVELOPMENT_DEVBOX.md)
 - http://localhost:8089/smsmockserver/ in the functest instance (see ../DEVELOPMENT_FUNCTESTS.md)

 both via a nginx snippet in ../devbox.d/nginx/nginx.template:

```nginx
        location ~ ^/smsmockserver(/.*)?$ {
            root ../../../smsmockserver/webroot;
            include fastcgi.conf;
            fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
            fastcgi_param SCRIPT_NAME /smsmockserver/index.php;
            fastcgi_param PATH_INFO $1;
            fastcgi_pass php_backend;
        }
```

It is normally not mounted in a production Jethro, where SMS_TESTMODE=true is generally sufficient for making SMS temporarily present but non-functional.
