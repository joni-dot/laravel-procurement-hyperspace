#!/usr/bin/env bash
# Makes a throwaway certificate authority and a certificate for the test S3 server (host name "s3"):
# pgBackRest talks to S3 over HTTPS only. Used by .github/workflows/backups.yml; the files go to
# .github/backup-drill/run/, which git ignores, and live only as long as the test.
set -euo pipefail

cd "$(dirname "$0")"
umask 077
mkdir -p run
cd run

openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=backup-drill-test-ca' \
    -keyout ca.key -out ca.crt 2> /dev/null
openssl req -newkey rsa:2048 -nodes -subj '/CN=s3' -keyout s3.key -out s3.csr 2> /dev/null
openssl x509 -req -in s3.csr -CA ca.crt -CAkey ca.key -CAcreateserial -days 1 \
    -extfile <(printf 'subjectAltName=DNS:s3\n') -out s3.crt 2> /dev/null
rm -f ca.key s3.csr ca.srl
# The containers read these as other users; the folder itself stays private to this user.
chmod 0644 ca.crt s3.crt s3.key
