<?php

// class which encapsulates complexity of getting jira issue
final class UberTask extends Phobject {
  private $console;
  private $workflow;
  private $jql = '';
  private $url = self::URL;
  private $conduit;

  const URL = 'https://arcanist-the-service.uberinternal.com/';
  const JIRA_CREATE_IN_URL = 'https://t3.uberinternal.com/secure/CreateIssueDetails!init.jspa?pid=%s&issuetype=10002&assignee=%s&summary=%s&description=%s';
  const JIRA_CREATE_URL = 'https://t3.uberinternal.com/secure/CreateIssue!default.jspa';
  const TASK_MSG = 'https://t3.uberinternal.com/browse/%s | %s';
  const ISSUE_URL = 'https://t3.uberinternal.com/browse/%s';
  const ISSUE_TYPE = '10002';
  const REFRESH_MSG = 'Refresh task list';
  const SKIP_MSG = 'Skip issue attachment';
  const CREATE_IN_PROJ_MSG = 'Create new task in %s';
  const CREATE_MSG = 'Create new task';
  const AUTOGENERATED = '[AutoGenerated - please remove me]';
  const TIMEOUT = 30;

  public function __construct(ArcanistWorkflow $workflow) {
    $this->console = PhutilConsole::getConsole();
    $this->workflow = $workflow;
  }

  public function setJQL($jql) {
    $this->jql = $jql;
    return $this;
  }

  public function setURL($url) {
    $this->url = $url;
    return $this;
  }

  public function getIssues() {
    $usso = new UberUSSO();
    $hostname = parse_url($this->url, PHP_URL_HOST);
    $token = $usso->maybeUseUSSOToken($hostname);
    if (!$token) {
      $token = $usso->getUSSOToken($hostname);
    }
    $payload = '{}';
    if ($this->jql) {
      $payload = json_encode(array('jql' => $this->jql));
    }
    $future = id(new HTTPSFuture($this->url, $payload))
      ->setFollowLocation(false)
      ->setTimeout(self::TIMEOUT)
      ->setMethod('POST')
      ->addHeader('Authorization', "Bearer ${token}")
      ->addHeader('Rpc-Caller', 'arcanist')
      ->addHeader('Rpc-Encoding', 'json')
      ->addHeader('Rpc-Procedure', 'ArcanistTheService::getIssues');
    list($body, $headers) = $future->resolvex();
    if (empty($body)) {
      return array();
    }
    $issues = phutil_json_decode($body);
    return idx($issues, 'issues', array());
  }

  public static function getJiraCreateIssueLink(
    $project_pid,
    $assignee,
    $summary,
    $description) {

    return sprintf(self::JIRA_CREATE_IN_URL,
                   urlencode($project_pid),
                   urlencode($assignee),
                   urlencode($summary),
                   urlencode($description));
  }

  public function getConduit() {
    return $this->workflow->getConduit();
  }

  public function openURIsInBrowser($uris) {
    try {
      return $this->workflow->openURIsInBrowser($uris);
    } catch (ArcanistUsageException $e) {
      $this->console->writeOut(
        "<bg:yellow>** Unable to open links %s in browser **</bg>\n",
        implode(' , ', $uris));
    }
  }

  public static function getTasksAndProjects($issues = array()) {
    $tasks = array();
    $projects = array();

    foreach ($issues as $issue) {
      $pkey = $issue['project']['key'];
      if (!isset($projects[$pkey])) {
        $projects[$pkey] = array(
          'id' => $issue['project']['id'],
          'tasks' => 0,
        );
      }
      $projects[$pkey]['tasks']++;
      $tasks[] = array('key' => $issue['key'], 'summary' => $issue['summary']);
    }
    return array($tasks, $projects);
  }

  public function getTaskTemplate() {
    return '%s

%s

# JIRA issue will be assigned to you. It will be created in `%s` project.
# Make sure to remove `[AutoGenerated - please remove me]` otherwise issue
# creation will be blocked!
#
# The first line is used as title, next lines as task description';
  }

  public function getJiraIssuesForAttachment($message) {
    while (true) {
      $this->console->writeOut(pht('Fetching issues from jira, patience please.')."\n");
      $issues = array();
      try {
        $issues = $this->getIssues();
      } catch (Exception $e) {
        $this->console->writeOut(
          pht("Something is wrong with jira, skipping...\n\n"));
        return array();
      } catch (Throwable $e) {
        $this->console->writeOut(
          pht("Something is wrong with jira, skipping...\n\n"));
        return array();
      }
      $for_search = array();

      list($tasks, $projects) = UberTask::getTasksAndProjects($issues);
      // add tasks to search list
      foreach ($tasks as $task) {
        $for_search[] = sprintf(self::TASK_MSG, $task['key'], $task['summary']);
      }
      // add refresh message
      $for_search[] = self::REFRESH_MSG;
      // need for way out in case user doesn't try using ESC/Ctrl+c/Ctrl+d
      $for_search[] = self::SKIP_MSG;
      // general jira task creation
      $for_search[] = self::CREATE_MSG;
      // sort projects by number of tasks
      uasort($projects,
        function ($v1, $v2) {
          return $v2['tasks'] - $v1['tasks'];
      });
      // attach create task in project XXX to the list
      foreach ($projects as $project => $v) {
        $for_search[] = sprintf(self::CREATE_IN_PROJ_MSG, $project);
      }

      // prompt user to choose from menu
      $fzf = id(new UberFZF());
      if (!$fzf->isFZFAvailable()) {
        $this->console->writeOut(
          "<bg:red>** %s **</bg>\n<bg:red>** %s **</bg>\n".
          "<bg:red>** %s **</bg> %s\n".
          "<bg:red>** %s **</bg>\n<bg:red>** %s **</bg>\n",
          pht('WARNING'),
          pht('WARNING'),
          pht('WARNING'),
          pht('Looks like you do not have `fzf`, please install using '.
              '`brew install fzf` or `apt-get install fzf` or `dnf install '.
              'fzf` and try again. Your productivity will improve if you '.
              "install this tool."),
          pht('WARNING'),
          pht('WARNING'));
      }
      $fzf->setMulti(50)
        ->setHeader('Select issue to attach to Differential Revision '.
                    '(use tab for multiple selection). You can skip adding '.
                    'task by pressing esc/ctrl+c/ctrl+d. If you just enter '.
                    'issue ID it will be used if it is not matching anything '.
                    'in the list.')
        ->setPrintQuery(true);
      $result = $fzf->fuzzyChoosePrompt($for_search);
      if (empty($result)) {
        // nothing was chosen (ctrl+d, ctrl+c)
        return;
      }

      // first item is query which was entered (if any)
      $query = $result[0];
      $result = array_slice($result, 1);
      $issues = array();
      foreach ($result as $line) {
        // restart whole outer loop
        if (trim($line) == self::REFRESH_MSG) {
          continue 2;
        }
        if (trim($line) == self::SKIP_MSG) {
          return;
        }
        if (trim($line) == self::CREATE_MSG) {
          $this->openURIsInBrowser(array(UberTask::JIRA_CREATE_URL));
          if (phutil_console_confirm('Do you want to refresh task list?',
                                     $default_no = false)) {
            continue 2;
          }
          return;
        }
        // fetch chosen tasks
        list($issue) = sscanf($line, self::TASK_MSG);
        if ($issue) {
          $issues[] = $issue;
        }
        // fetch projects where user want to create task
        list($project) = sscanf($line, self::CREATE_IN_PROJ_MSG);
        if ($project) {
          $title = self::AUTOGENERATED.' '.$message->getFieldValue('title');
          $description = $message->getFieldValue('summary');
          $content = sprintf(
            $this->getTaskTemplate(), $title, $description, $project);
          while (true) {
            // todo use preffered editor if necessary
            $editor = $this->newInteractiveEditor($content);
            $content = $editor->setName('new-task')->editInteractively();
            $parsed = UberJiraIssueMessageParser::parse($content);
            $title = $parsed['title'];
            $description = $parsed['description'];
            if (strpos($title, self::AUTOGENERATED) !== false) {
              $this->console->writeOut("JIRA issue has errors:\n");
              $this->console->writeOut("    - You didn't remove autogenerated ".
                "lines, please remove them!\n");
              $this->console->writeOut('You must resolve these errors to '.
                'create issue.');
              if (!phutil_console_confirm('Do you want to edit the issue?')) {
                break;
              }
              $content = sprintf(
                $this->getTaskTemplate(), $title, $description, $project);
              $content .= "# Resolve these errors:\n";
              $content .= "#  - You didn't remove autogenerated lines, ".
                'please remove them!';
              continue;
            }
            $project_id = $projects[$project]['id'];
            $jira_issue = $this
              ->getConduit()
              ->callMethodSynchronous('uber_jira.create_issue',
                array(
                  'project_id' => $project_id,
                  'issue_type' => self::ISSUE_TYPE,
                  'title' => $title,
                  'description' => $description,
                ));
            $issues[] = $jira_issue['key'];
            $issue_url = sprintf(self::ISSUE_URL, $jira_issue['key']);
            $this->console
              ->writeOut(pht("Jira issue %s created\n", $issue_url));
            $this->openURIsInBrowser(array($issue_url));
            break;
          }
        }
      }
      if (!empty($issues)) {
        return $issues;
      } else {
        $matches = array();
        if (preg_match_all('/([A-Z][A-Z0-9]*-[1-9]\d*)/',
          $query,
          $matches) !== 0) {
          return idx($matches, 0);
        }
      }
    }
  }

  final protected function newInteractiveEditor($text) {
    $editor = new PhutilInteractiveEditor($text);

    $preferred = $this->workflow->getConfigFromAnySource('editor');
    if ($preferred) {
      $editor->setPreferredEditor($preferred);
    }

    return $editor;
  }
}