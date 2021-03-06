<?php
namespace Drush\Commands\sql;

use Consolidation\AnnotatedCommand\CommandData;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\Exceptions\UserAbortException;
use Drush\SiteAlias\AliasRecord;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;
use Drush\SiteAlias\SiteAliasManagerAwareTrait;
use Symfony\Component\Config\Definition\Exception\Exception;
use Webmozart\PathUtil\Path;

class SqlSyncCommands extends DrushCommands implements SiteAliasManagerAwareInterface
{
    use SiteAliasManagerAwareTrait;

    /**
     * Copy DB data from a source site to a target site. Transfers data via rsync.
     *
     * @command sql:sync
     * @aliases sql-sync
     * @param $source A site-alias or the name of a subdirectory within /sites whose database you want to copy from.
     * @param $destination A site-alias or the name of a subdirectory within /sites whose database you want to replace.
     * @optionset_table_selection
     * @option no-dump Do not dump the sql database; always use an existing dump file.
     * @option no-sync Do not rsync the database dump file from source to target.
     * @option runner Where to run the rsync command; defaults to the local site. Can also be 'source' or 'destination'.
     * @option create-db Create a new database before importing the database dump on the target machine.
     * @option db-su Account to use when creating a new database (e.g. root).
     * @option db-su-pw Password for the db-su account.
     * @option source-dump The path for retrieving the sql-dump on source machine.
     * @option target-dump The path for storing the sql-dump on destination machine.
     * @usage drush sql:sync @source @target
     *   Copy the database from the site with the alias 'source' to the site with the alias 'target'.
     * @usage drush sql:sync #prod #dev
     *   Copy the database from the site in /sites/prod to the site in /sites/dev (multisite installation).
     * @topics docs:aliases,docs:policy,docs:example-sync-via-http
     */
    public function sqlsync($source, $destination, $options = ['no-dump' => false, 'no-sync' => false, 'runner' => self::REQ, 'create-db' => false, 'db-su' => self::REQ, 'db-su-pw' => self::REQ, 'target-dump' => self::REQ, 'source-dump' => self::OPT])
    {
        $manager = $this->siteAliasManager();
        $sourceRecord = $manager->get($source);
        $destinationRecord = $manager->get($destination);

        $backend_options = [];
        $global_options = Drush::redispatchOptions()  + ['strict' => 0];

        if (Drush::simulate()) {
            $backend_options['backend-simulate'] = true;
        }

        // Create destination DB if needed.
        if ($options['create-db']) {
            $this->logger()->notice(dt('Starting to create database on Destination.'));
            $return = drush_invoke_process($destination, 'sql-create', array(), $global_options, $backend_options);
            if ($return['error_status']) {
                throw new \Exception(dt('sql-create failed.'));
            }
        }

        // Perform sql-dump on source unless told otherwise.
        $dump_options = $global_options + array(
            'gzip' => true,
            'result-file' => $options['source-dump'] ?: true,
        );
        if (!$options['no-dump']) {
            $this->logger()->notice(dt('Starting to dump database on Source.'));
            $return = drush_invoke_process($source, 'sql-dump', array(), $dump_options, $backend_options);
            if ($return['error_status']) {
                throw new \Exception(dt('sql-dump failed.'));
            } else {
                $source_dump_path = $return['object'];
                if (!is_string($source_dump_path)) {
                    throw new \Exception(dt('The Drush sql-dump command did not report the path to the dump file produced.  Try upgrading the version of Drush you are using on the source machine.'));
                }
            }
        } else {
            $source_dump_path = $options['source-dump'];
        }

        $do_rsync = !$options['no-sync'];
        // Determine path/to/dump on destination.
        if ($options['target-dump']) {
            $destination_dump_path = $options['target-dump'];
            $backend_options['interactive'] = false;  // @temporary: See https://github.com/drush-ops/drush/pull/555
        } elseif (!$sourceRecord->isRemote() && !$destinationRecord->isRemote()) {
            $destination_dump_path = $source_dump_path;
            $do_rsync = false;
        } else {
            $tmp = '/tmp'; // Our fallback plan.
            $this->logger()->notice(dt('Starting to discover temporary files directory on Destination.'));
            $return = drush_invoke_process($destination, 'core-status', array(), array(), array('integrate' => false, 'override-simulated' => true));
            if (!$return['error_status'] && isset($return['object']['drush-temp'])) {
                $tmp = $return['object']['drush-temp'];
            }
            $destination_dump_path = Path::join($tmp, basename($source_dump_path));
            $backend_options['interactive'] = false;  // No need to prompt as destination is a tmp file.
        }

        if ($do_rsync) {
            $rsync_options = [];
            if (!$options['no-dump']) {
                // Cleanup if this command created the dump file.
                $rsync_options[] = '--remove-source-files';
            }
            if (!$runner = $options['runner']) {
                $runner = $sourceRecord->isRemote() && $destinationRecord->isRemote() ? $destination : '@self';
            }
            // Since core-rsync is a strict-handling command and drush_invoke_process() puts options at end, we can't send along cli options to rsync.
            // Alternatively, add options like --ssh-options to a site alias (usually on the machine that initiates the sql-sync).
            $return = drush_invoke_process($runner, 'core-rsync', array_merge(["$source:$source_dump_path", "$destination:$destination_dump_path", '--'], $rsync_options), [], $backend_options);
            $this->logger()->notice(dt('Copying dump file from Source to Destination.'));
            if ($return['error_status']) {
                throw new \Exception(dt('core-rsync failed.'));
            }
        }

        // Import file into destination.
        $this->logger()->notice(dt('Starting to import dump file onto Destination database.'));
        $query_options = $global_options + array(
            'file' => $destination_dump_path,
            'file-delete' => true,
        );
        $return = drush_invoke_process($destination, 'sql-query', array(), $query_options, $backend_options);
        if ($return['error_status']) {
            throw new Exception('Failed to rsync the database dump from source to destination.');
        }
    }

    /**
     * @hook validate sql-sync
     */
    public function validate(CommandData $commandData)
    {
        $source = $commandData->input()->getArgument('source');
        $destination = $commandData->input()->getArgument('destination');
        // Get destination info for confirmation prompt.
        $manager = $this->siteAliasManager();
        if (!$sourceRecord = $manager->get($source)) {
            throw new \Exception(dt('Error: no alias record could be found for source !source', array('!source' => $source)));
        }
        if (!$destinationRecord = $manager->get($destination)) {
            throw new \Exception(dt('Error: no alias record could be found for target !destination', array('!destination' => $destination)));
        }
        if (!$source_db_name = $this->databaseName($sourceRecord)) {
            throw new \Exception(dt('Error: no database record could be found for source !source', array('!source' => $source)));
        }
        if (!$target_db_name = $this->databaseName($destinationRecord)) {
            throw new \Exception(dt('Error: no database record could be found for target !destination', array('!destination' => $destination)));
        }
        $txt_source = ($sourceRecord->remoteHost() ? $sourceRecord->remoteHost() . '/' : '') . $source_db_name;
        $txt_destination = ($destinationRecord->remoteHost() ? $destinationRecord->remoteHost() . '/' : '') . $target_db_name;

        if ($commandData->input()->getOption('no-dump') && !$commandData->input()->getOption('source-dump')) {
            throw new \Exception(dt('The --source-dump option must be supplied when --no-dump is specified.'));
        }

        if ($commandData->input()->getOption('no-sync') && !$commandData->input()->getOption('target-dump')) {
            throw new \Exception(dt('The --target-dump option must be supplied when --no-sync is specified.'));
        }

        if (!Drush::simulate()) {
            $this->output()->writeln(dt("You will destroy data in !target and replace with data from !source.", array(
                '!source' => $txt_source,
                '!target' => $txt_destination
            )));
            if (!$this->io()->confirm(dt('Do you really want to continue?'))) {
                throw new UserAbortException();
            }
        }
    }

    public function databaseName(AliasRecord $record)
    {
        $values = drush_invoke_process($record, "core-status", array(), array(), array('integrate' => false, 'override-simulated' => true));
        if (is_array($values) && ($values['error_status'] == 0)) {
            return $values['object']['db-name'];
        }
    }
}
