<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Extension\ExtensionRegistry;
use CajeerEngine\Forum\{ForumDatabaseCheck,ForumIntegrityCheck};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Forum\Migration\ForumMigrationEngine;
use CajeerEngine\Database\Repository\RoleRepository;

/** Operator/ACP readiness, deliberately not a per-request health probe. */
final readonly class ForumEcosystemCheck
{
    public function __construct(private DatabaseManager $db,private ConfigRepository $config) {}

    public function check(?string $target=null,bool $deep=false): array
    {
        $version=$target??$this->config->string('app.edition_version');
        $checks=[];$extensions=[];
        $probe=static function(callable $fn): bool {try{return $fn()===true;}catch(\Throwable){return false;}};
        $checks['extensions']=$probe(function()use($version,&$extensions){
            $extensions=(new ExtensionRegistry($this->config->rootPath(),$this->config))->targetCompatibility($version);
            return $extensions['ok'];
        });
        // One snapshot for the policy, theme and role checks.
        try{$this->db->readSnapshot(function()use(&$checks,$probe){
            $checks['community_policy']=$probe(function(){
                $raw=$this->db->connection()->query('SELECT payload FROM ce_forum_community_settings WHERE id=1')->fetchColumn();
                if($raw===false)return false;
                $data=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
                // Empty document is the original migration default; stored documents are complete.
                if($data===[])return true;
                ForumCommunitySettings::validate($data);return true;
            });
            $checks['theme']=$probe(function(){(new ForumThemeService($this->db))->snapshot();return true;});
            $checks['delegation']=$probe(function(){
                $roles=new RoleRepository($this->db);
                foreach(ForumAdminService::DELEGATED as $scope){$role=$roles->find('forum-acp-'.substr($scope,10));if($role===null||$role['permissions']!==[$scope])return false;}
                return true;
            });
            $checks['migration_idle']=$probe(function(){ForumMigrationEngine::assertNoRunning($this->db);return true;});
        });}catch(\Throwable){foreach(['community_policy','theme','delegation','migration_idle'] as $name)$checks[$name]=false;}
        if($deep){
            $checks['schema']=$probe(fn()=>(new ForumDatabaseCheck($this->db))->check()['ok']);
            $checks['integrity']=$probe(fn()=>(new ForumIntegrityCheck($this->db))->check()['ok']);
            $checks['search']=$probe(fn()=>(new ForumSearchMaintenance($this->db))->check()['ok']);
        }
        return ['ok'=>!in_array(false,$checks,true),'target'=>$version,'checks'=>$checks,'extensions'=>$extensions,'deep'=>$deep];
    }

    public function assertReady(): void
    {
        $report=$this->check();
        if(!$report['ok'])throw new \RuntimeException('Forum ecosystem check failed: '.implode(', ',array_keys(array_filter($report['checks'],static fn($ok)=>!$ok))));
    }
}
