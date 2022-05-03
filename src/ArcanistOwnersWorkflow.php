<?php
/**
 * Copyright 2020 Pinterest, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Display ownership information for a list of files.
 */
final class ArcanistOwnersWorkflow extends ArcanistWorkflow {

    public function getWorkflowName() {
        return 'owners';
    }

    public function getCommandSynopses() {
        return phutil_console_format(<<<EOTEXT
      **owners** [__options__] [__path__ ...]
EOTEXT
      );
    }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: git, hg
          Display ownership information for a list of files.

          Without __paths__, the files changed in your local working copy will
          be used.
EOTEXT
      );
  }

  public function requiresConduit() {
    return true;
  }

  public function requiresAuthentication() {
    return true;
  }

  public function requiresRepositoryAPI() {
    return true;
  }

  public function getArguments() {
    return array(
      'output' => array(
        'param' => 'format',
        'support' => array(
          'json',
        ),
        'help' => pht(
          "With '%s', show owners in machine-readable JSON format.",
          'json'),
      ),
      '*' => 'paths',
    );
  }

  public function getSupportedRevisionControlSystems() {
    return array('git', 'hg');
  }

  public function run() {
    $paths = $this->selectPathsForWorkflow(
      $this->getArgument('paths'),
      null,
      ArcanistRepositoryAPI::FLAG_UNTRACKED);

    $base_uri = $this
      ->getWorkingCopy()
      ->getProjectConfig('phabricator.uri');

    $slack_uri = $this
      ->getWorkingCopy()
      ->getProjectConfig('slack.uri');

    $slack_field = $this
      ->getWorkingCopy()
      ->getProjectConfig('owners.slack_field');

    // Map each input path to all of its matching packages.
    $bypath = array();
    foreach ($this->queryPackages($paths) as $package) {
      $fields = idx($package, 'fields', array());
      if (!empty($base_uri)) {
        $fields['url'] = (string)id(new PhutilURI($base_uri))
            ->setPath("/owners/package/{$package['id']}/");
      }

      if (!empty($slack_field)) {
        $channel_name = idx($fields, $slack_field);
        $fields['slack']['channel_name'] = $channel_name ?
          ltrim(trim($channel_name), '#') : null;
        if (!empty($slack_uri)) {
          $fields['slack']['channel_uri'] = $fields['slack']['channel_name'] ?
          (string)id(new PhutilURI($slack_uri))
            ->setPath("/channels/{$fields['slack']['channel_name']}/") : null;
        }
      }

      foreach ($this->matchPackagePaths($package, $paths) as $path) {
        $bypath[$path][] = $fields;
      }
    }
    ksort($bypath);

    if ($this->getArgument('output') == 'json') {
      $this->outputJson($bypath);
    } else {
      $this->outputText($bypath);
    }
  }

  protected function outputJson($bypath) {
    echo json_encode($bypath)."\n";
  }

  protected function outputText($bypath) {
    // The paths are initially sorted alphabetically, but we display them
    // grouped based on their common package. For example, if we have paths
    // "A", "B', "C", and both "A" and "C" are owned by the same packages, the
    // output will look like:
    //
    //    A
    //    C
    //      Package Bar
    //      Package Baz
    //    B
    //      Package Foo
    //
    // Packages within each group are grouped by their ownership strength
    // ("strong" before "weak") and then listed alphabetically. Weak owners
    // aren't shown if there are stronger owners available.
    while (!empty($bypath)) {
      $packages = reset($bypath);

      foreach ($bypath as $path => $path_packages) {
        if ($path_packages === $packages) {
          echo phutil_console_format("**%s**\n", $path);
          unset($bypath[$path]);
        }
      }

      $previouslyWeak = true;
      foreach (isort($packages, 'dominion') as $package) {
        $weak = $package['dominion']['value'] == 'weak';
        if ($weak && !$previouslyWeak) {
          break;
        }

        $slack = '';
        if ($channel = idx(idx($package, 'slack', array()), 'channel_name')) {
          $slack_data = idx($package, 'slack', array());
          $slack = sprintf(' (Slack: %s)', idx($slack_data, 'channel_uri') ?
            $this->linkify('#'.$channel, idx($slack_data, 'channel_uri')) :
            '#'.$channel);
        }

        echo phutil_console_format("  %s%s\n",
          $this->linkify($package['name'], $package['url']), $slack);

        $previouslyWeak = $weak;
      }
    }
  }

  private function queryPackages($paths) {
    $result = $this->getConduit()->callMethodSynchronous(
      'owners.search',
      array(
        'constraints' => array(
          'repositories' => array($this->getRepositoryPHID()),
          'paths' => $paths,
          'statuses' => array('active'),
        ),
        'attachments' => array(
          'paths' => true,
        ),
        'order' => 'name'
      ));

    return idx($result, 'data', array());
  }

  private function matchPackagePaths($package, $paths) {
    $included = array();
    $excluded = array();
    foreach ($package['attachments']['paths']['paths'] as $spec) {
      if ($spec['repositoryPHID'] !== $this->getRepositoryPHID()) {
        continue;
      }
      $path = trim($spec['path'], '/');
      if (empty($spec['excluded'])) {
        $included[] = $path;
      } else {
        $excluded[] = $path;
      }
    }

    $matches = array();
    foreach ($paths as $path) {
      if ($this->containsPath($included, $path) &&
          !$this->containsPath($excluded, $path)) {
        $matches[] = $path;
      }
    }

    return $matches;
  }

  private function containsPath($paths, $candidate) {
    foreach ($paths as $path) {
      if (substr($candidate, 0, strlen($path)) === $path) {
        return true;
      }
    }
    return false;
  }

  // https://gist.github.com/egmontkob/eb114294efbcd5adb1944c9f3cb5feda
  private function linkify($text, $url) {
    return (!empty($url)) ? "\e]8;;$url\e\\$text\e]8;;\e\\" : $text;
  }
}
