<?php
// Copyright 1999-2017. Parallels IP Holdings GmbH.
class Modules_SlaveDnsManager_Rndc
{
    private function _call(Modules_SlaveDnsManager_Slave $slave, $arguments, $verbose = false)
    {
        $arguments = join(' ', [
            "-b \"{$slave->getMasterIp()}\"",
            "-s \"{$slave->getIp()}\"",
            "-p \"{$slave->getPort()}\"",
            "-y \"{$slave->getRndcKeyId()}\"",
            "-c \"{$slave->getConfigPath()}\"",
            $arguments,
        ]);

        if (pm_ProductInfo::isWindows()) {
            $command = '"' . PRODUCT_ROOT . '\dns\bin\rndc.exe"';
        } else {
            $command = '/usr/sbin/rndc';
        }
        exec("{$command} {$arguments} 2>&1", $out, $code);
        $output = implode("\n", $out);

        if ($verbose) {
            if ($code != 0) {
                throw new pm_Exception("$command $arguments\n$output\n\nError code: $code");
            }
            return $output;
        }

        if ($code != 0) {
            // Cannot send output header due to possible API-RPC calls
            error_log("Error code $code: $output");
        }

        return $code == 0;
    }
    
    public function isAuthoritative($domain)
    {
      /**
       * It does not appear that there's a difference between a DNS_NS lookup and using the pass by ref $authoritative var
       * $dns = dns_get_record($domain, DNS_NS, $authoritative);
       * Important: if this server uses localhost as a resolver, this test will fail.
       */
      $dns = dns_get_record($domain, DNS_A + DNS_NS);
      $nameserver_ips = [];
      foreach ($dns as $record) {
        switch ($record['type']) {
          case 'A':
            $a_record = $record['ip'];
            break;
          case 'NS':
            $nameserver_ips[] = gethostbyname($record['target']);
            break;
          default:
            continue;
        }         
      }
      
      return in_array($a_record, $nameserver_ips);
    }

    public function addZone($domain, Modules_SlaveDnsManager_Slave $slave = null)
    {
        $slaves = null === $slave ? Modules_SlaveDnsManager_Slave::getList() : [$slave];
        foreach ($slaves as $slave) {
            $this->_call($slave, "addzone \"{$domain}\" \"{$slave->getRndcClass()}\" \"{$slave->getRndcView()}\"" .
                " \"{ type slave; file \\\"{$domain}\\\"; masters { {$slave->getMasterPublicIp()}; }; };\"");
        }
    }

    public function updateZone($domain, Modules_SlaveDnsManager_Slave $slave = null)
    {
        $slaves = null === $slave ? Modules_SlaveDnsManager_Slave::getList() : [$slave];
        foreach ($slaves as $slave) {
            $result = $this->_call($slave, "refresh \"{$domain}\" \"{$slave->getRndcClass()}\" \"{$slave->getRndcView()}\"");
            if (false === $result) {
                $this->addZone($domain, $slave);
            }
        }
    }

    public function deleteZone($domain, Modules_SlaveDnsManager_Slave $slave = null)
    {
        $slaves = null === $slave ? Modules_SlaveDnsManager_Slave::getList() : [$slave];
        foreach ($slaves as $slave) {
            $slaveStatus = $this->checkStatus($slave);
            // version: 9.9.4-RedHat-9.9.4-51.el7_4.2 (none) <id:8f9657aa>
            // version: BIND 9.10.3-P4-Ubuntu <id:ebd72b3> (none)
            $cleanFlag = (preg_match("/version: (BIND )?(9\.10\.\d+|9\.11\.\d+|9\.12\.\d+)/", $slaveStatus)) ? "-clean" : ""; 

            $this->_call($slave, "delzone $cleanFlag \"{$domain}\" \"{$slave->getRndcClass()}\" \"{$slave->getRndcView()}\"");
        }
    }

    public function checkStatus(Modules_SlaveDnsManager_Slave $slave)
    {
        return $this->_call($slave, "status", true);
    }
}
