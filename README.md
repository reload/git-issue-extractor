# Git Issue extractor
Tool for extracting Jira issue-references from git-logs

## Installation
```
composer install
cp app/config/dist.parameters.xml app/config/parameters.xml
```
Edit app/config/parameters.xml and insert values. Eg.
```
<?xml version="1.0" encoding="UTF-8" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services
        http://symfony.com/schema/dic/services/services-1.0.xsd">
    <parameters>
        <parameter key="issueextractor.jira_endpoint">https://reload.atlassian.net</parameter>
        <parameter key="issueextractor.jira_username">m_danquah</parameter>
        <parameter key="issueextractor.jira_password">nevergonnagiveyouup</parameter>
    </parameters>
</container>
```

## Usage

```
cd /some/project/clone
/path/to/git-issue-extractor/git-issue-extractor my-prod-tag my-next-release-tag
```
Will examine commits between the two revisions and detect references to Jira project-keys and output a report.

Pr default the tool will output the issues in a table format:

```
+--------------------------------------------------+----------------+---------------------------------------+
| Url                                              | Status         | Title                                 |
+--------------------------------------------------+----------------+---------------------------------------+
| https://reload.atlassian.net/browse/SOMEPROJ-123 | Ready for Test | Clever title for issue 1              |
| https://reload.atlassian.net/browse/LOLS-42      | Ready for Test | I'm just making this stuff up as I go |
+--------------------------------------------------+----------------+---------------------------------------+

```

If you add --release-note you will get the issues formatted in a form more suitable for a release-note.
```
- Clever title for issue 1
 https://reload.atlassian.net/browse/SOMEPROJ-123

- I'm just making this stuff up as I go
 https://reload.atlassian.net/browse/LOLS-42
```
