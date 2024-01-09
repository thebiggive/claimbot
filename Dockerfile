FROM thebiggive/php:8.3

# Install the AWS CLI - needed to load in secrets safely from S3. See https://aws.amazon.com/blogs/security/how-to-manage-secrets-for-amazon-ec2-container-service-based-applications-by-using-amazon-s3-and-docker/
RUN apt-get update -qq && apt-get install -y awscli && \
    rm -rf /var/lib/apt/lists/* /var/cache/apk/*

ADD . /var/www/html

RUN composer install --no-interaction --quiet --optimize-autoloader --no-dev

EXPOSE 80
