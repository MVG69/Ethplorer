<?php
/*!
 * Copyright 2016 Everex https://everex.io
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

require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/profiler.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use \Litipk\BigNumbers\Decimal as Decimal;

class Ethplorer {

    /**
     * Chainy contract address
     */
    const ADDRESS_CHAINY = '0xf3763c30dd6986b53402d41a8552b8f7f6a6089b';

    /**
     * Settings
     *
     * @var array
     */
    protected $aSettings = array();

    /**
     * MongoDB collections.
     *
     * @var array
     */
    protected $dbs;

    /**
     * Singleton instance.
     *
     * @var Ethplorer
     */
    protected static $oInstance;

    /**
     * Cache storage.
     *
     * @var evxCache
     */
    protected $oCache;

    /**
     *
     * @var int
     */
    protected $pageSize = 0;

    /**
     *
     * @var array
     */
    protected $aPager = array();

    /**
     *
     * @var string
     */
    protected $filter = FALSE;

    /**
     * Constructor.
     *
     * @throws Exception
     */
    protected function __construct(array $aConfig){
        evxProfiler::checkpoint('START');
        $this->aSettings = $aConfig;
        $this->aSettings += array(
            "cacheDir" => dirname(__FILE__) . "/../cache/",
            "logsDir" => dirname(__FILE__) . "/../logs/",
        );

        $this->oCache = new evxCache($this->aSettings['cacheDir']);
        if(isset($this->aSettings['mongo']) && is_array($this->aSettings['mongo'])){
            if(class_exists("MongoClient")){
                $oMongo = new MongoClient($this->aSettings['mongo']['server']);
                $oDB = $oMongo->{$this->aSettings['mongo']['dbName']};
                $this->dbs = array(
                    'transactions' => $oDB->{"everex.eth.transactions"},
                    'blocks'       => $oDB->{"everex.eth.blocks"},
                    'contracts'    => $oDB->{"everex.eth.contracts"},
                    'tokens'       => $oDB->{"everex.erc20.contracts"},
                    'operations'   => $oDB->{"everex.erc20.operations"},
                    'balances'     => $oDB->{"everex.erc20.balances"}
                );
                // Get last block
                $lastblock = $this->getLastBlock();
                $this->oCache->store('lastBlock', $lastblock);
            }else{
                throw new Exception("MongoClient class not found, php_mongo extension required");
            }
        }
    }

    /**
     * Returns cache object
     *
     * @return evxCache
     */
    public function getCache(){
        return $this->oCache;
    }

    /**
     * Singleton getter.
     *
     * @return Ethereum
     */
    public static function db(array $aConfig = array()){
        if(is_null(self::$oInstance)){
            self::$oInstance = new Ethplorer($aConfig);
        }
        return self::$oInstance;
    }

    /**
     * Sets new page size.
     *
     * @param int $pageSize
     */
    public function setPageSize($pageSize){
        $this->pageSize = $pageSize;
    }

    /**
     * Sets current page offset for section
     *
     * @param string $section
     * @param int $page
     */
    public function setPager($section, $page = 1){
        $this->aPager[$section] = $page;
    }

    /**
     * Return page offset for section
     *
     * @param string $section
     * @return int
     */
    public function getPager($section){
        return isset($this->aPager[$section]) ? $this->aPager[$section] : 1;
    }

    /**
     * Set filter value
     *
     * @param string $filter
     */
    public function setFilter($filter){
        $this->filter = $filter;
    }

    /**
     * Returns item offset for section.
     *
     * @param string $section
     * @int type
     */
    public function getOffset($section){
        $limit = $this->pageSize;
        return (1 === $this->getPager($section)) ? FALSE : ($this->getPager($section) - 1) * $limit;
    }

    /**
     * Returns true if provided string is a valid ethereum address.
     *
     * @param string $address  Address to check
     * @return bool
     */
    public function isValidAddress($address){
        return (is_string($address)) ? preg_match("/^0x[0-9a-f]{40}$/", $address) : false;
    }

    /**
     * Returns true if provided string is a valid ethereum tx hash.
     *
     * @param string  $hash  Hash to check
     * @return bool
     */
    public function isValidTransactionHash($hash){
        return (is_string($hash)) ? preg_match("/^0x[0-9a-f]{64}$/", $hash) : false;
    }

    /**
     * Returns true if provided string is a chainy contract address.
     *
     * @param type $address
     * @return bool
     */
    public function isChainyAddress($address){
        return ($address === self::ADDRESS_CHAINY);
    }

    /**
     * Returns advanced address details.
     *
     * @param string $address
     * @return array
     */
    public function getAddressDetails($address, $limit = 50){
        $result = array(
            "isContract"    => FALSE,
            "transfers"     => array()
        );
        if($this->pageSize){
            $limit = $this->pageSize;
        }
        $refresh = isset($this->aPager['refresh']) ? $this->aPager['refresh'] : FALSE;
        if(!$refresh){
            $result['balance'] = $this->getBalance($address);
            $result['balanceOut'] = $this->getEtherTotalOut($address);
            $result['balanceIn'] = $result['balanceOut'] + $result['balance'];
        }
        $contract = $this->getContract($address);
        $token = FALSE;
        if($contract){
            $result['isContract'] = TRUE;
            $result['contract'] = $contract;
            if($token = $this->getToken($address)){
                $result["token"] = $token;
            }elseif($this->isChainyAddress($address)){
                $result['chainy'] = $this->getChainyTransactions($limit, $this->getOffset('chainy'));
                $count = $this->countChainy();
                $result['pager']['chainy'] = array(
                    'page' => $this->getPager('chainy'),
                    'records' => $count,
                    'total' => $this->filter ? $this->countChainy(FALSE) : $count
                );
            }
        }else{
            $token = $this->getToken($address);
            if(is_array($token)){
                $result['isContract'] = TRUE;
                // @todo
                $result['contract'] = array();
                $result["token"] = $token;
            }
        }
        if($result['isContract'] && isset($result['token'])){
            $result['pager'] = array('pageSize' => $limit);
            foreach(array('transfers', 'issuances', 'holders') as $type){
                if(!$refresh || ($type === $refresh)){
                    $page = $this->getPager($type);
                    $offset = $this->getOffset($type);
                    switch($type){
                        case 'transfers':
                            $count = $this->getContractOperationCount('transfer', $address);
                            $total = $this->filter ? $this->getContractOperationCount('transfer', $address, FALSE) : $count;
                            $cmd = 'getContractTransfers';
                            break;
                        case 'issuances':
                            $count = $this->getContractOperationCount(array('$in' => array('issuance', 'burn', 'mint')), $address);
                            $total = $this->filter ? $this->getContractOperationCount(array('$in' => array('issuance', 'burn', 'mint')), $address, FALSE) : $count;
                            $cmd = 'getContractIssuances';
                            break;
                        case 'holders':
                            $count = $this->getTokenHoldersCount($address);
                            $total = $this->filter ? $this->getTokenHoldersCount($address, FALSE) : $count;
                            $cmd = 'getTokenHolders';
                            break;
                    }
                    if($offset && ($offset > $count)){
                        $offset = 0;
                        $page = 1;
                    }
                    $result[$type] = $this->{$cmd}($address, $limit, $offset);;
                    $result['pager'][$type] = array(
                        'page' => $page,
                        'records' => $count,
                        'total' => $total
                    );
                }
            }
        }
        if(!isset($result['token']) && !isset($result['pager'])){
            // Get balances
            $result["tokens"] = array();
            $result["balances"] = $this->getAddressBalances($address);
            foreach($result["balances"] as $balance){
                $balanceToken = $this->getToken($balance["contract"]);
                if($balanceToken){
                    $result["tokens"][$balance["contract"]] = $balanceToken;
                }
            }
            $result["transfers"] = $this->getAddressOperations($address, $limit, $this->getOffset('transfers'));
            $result['pager']['transfers'] = array(
                'page' => $this->getPager('transfers'),
                'records' => $this->countOperations($address),
                'total' => $this->countOperations($address, FALSE),
            );
        }
        return $result;
    }

    public function getTokenTotalInOut($address){
        $t1 = microtime(true);
        $result = array('totalIn' => 0, 'totalOut' => 0);
        if($this->isValidAddress($address)){
            $cursor = $this->dbs['balances']->aggregate(
                array(
                    array('$match' => array("contract" => $address)),
                    array(
                        '$group' => array(
                            "_id" => '$contract',
                            'totalIn' => array('$sum' => '$totalIn'),
                            'totalOut' => array('$sum' => '$totalOut')
                        )
                    ),
                )
            );
            if($cursor){
                foreach($cursor as $record){
                    if(isset($record[0])){
                        if(isset($record[0]['totalIn'])){
                            $result['totalIn'] += floatval($record[0]['totalIn']);
                        }
                        if(isset($record[0]['totalOut'])){
                            $result['totalOut'] += floatval($record[0]['totalOut']);
                        }
                    }
                }
            }
        }
        return $result;
    }


    public function getEtherTotalOut($address){
        $result = 0;
        if($this->isValidAddress($address)){
            $cursor = $this->dbs['transactions']->aggregate(
                array(
                    array('$match' => array("from" => $address)),
                    array(
                        '$group' => array(
                            "_id" => '$from',
                            'out' => array('$sum' => '$value')
                        )
                    ),
                )
            );
            if($cursor){
                foreach($cursor as $record){
                    if(isset($record[0])){
                        if(isset($record[0]['out'])){
                            $result += floatval($record[0]['out']);
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Returns transactions list for a specific address.
     *
     * @param string  $address
     * @param int     $limit
     * @return array
     */
    public function getTransactions($address, $limit = 10, $showZero = FALSE){
        $result = array();
        $search = array('$or' => array(array("from" => $address), array("to" => $address)));
        if(!$showZero){
            $search = array('$and' => array($search, array('value' => array('$gt' => 0))));
        }
        $cursor = $this->dbs['transactions']->find($search)->sort(array("timestamp" => -1))->limit($limit);
        foreach($cursor as $tx){
            unset($tx["_id"]);
            $result[] = array(
                'timestamp' => $tx['timestamp'],
                'from' => $tx['from'],
                'to' => $tx['to'],
                'hash' => $tx['hash'],
                'value' => $tx['value'],
                'input' => $tx['input'],
            );
        }
        return $result;
    }

    /**
     * Returns advanced transaction data.
     *
     * @param string  $hash  Transaction hash
     * @return array
     */
    public function getTransactionDetails($hash){
        $cache = 'tx-' . $hash;
        $result = $this->oCache->get($cache, false, true);
        if(false === $result){
            $tx = $this->getTransaction($hash);
            $result = array(
                "tx" => $tx,
                "contracts" => array()
            );
            $tokenAddr = false;
            if(isset($tx["creates"]) && $tx["creates"]){
                $result["contracts"][] = $tx["creates"];
                $tokenAddr = $tx["creates"];
            }
            $fromContract = $this->getContract($tx["from"]);
            if($fromContract){
                $result["contracts"][] = $tx["from"];
            }
            if(isset($tx["to"]) && $tx["to"]){
                if($this->getContract($tx["to"])){
                    $result["contracts"][] = $tx["to"];
                    $tokenAddr = $tx["to"];
                }
            }
            $result["contracts"] = array_values(array_unique($result["contracts"]));
            if($tokenAddr){
                if($token = $this->getToken($tokenAddr)){
                    $result['token'] = $token;
                }
            }
            $result["operations"] = $this->getOperations($hash);
            if(is_array($result["operations"]) && count($result["operations"])){
                foreach($result["operations"] as $idx => $operation){
                    if($result["operations"][$idx]['contract'] !== $tx["to"]){
                        $result["contracts"][] = $operation['contract'];
                    }
                    if($token = $this->getToken($operation['contract'])){
                        $result['token'] = $token;
                        $result["operations"][$idx]['type'] = ucfirst($operation['type']);
                        $result["operations"][$idx]['token'] = $token;
                    }
                }
            }
            if($result['tx']){
                $this->oCache->save($cache, $result);
            }
        }
        if(is_array($result) && is_array($result['tx'])){
            $result['tx']['confirmations'] = $this->oCache->get('lastBlock') - $result['tx']['blockNumber'] + 1;
        }
        if(is_array($result) && is_array($result['token'])){
            $result['token'] = $this->getToken($result['token']['address']);
        }
        return $result;
    }

    /**
     * Return address ETH balance.
     *
     * @param string  $address  Address
     * @return double
     */
    public function getBalance($address){
        // @todo: cache
        $balance = $this->_callRPC('eth_getBalance', array($address, 'latest'));
        if(false !== $balance){
            $balance = hexdec(str_replace('0x', '', $balance)) / pow(10, 18);
        }
        return $balance;
    }

    /**
     * Return transaction data by transaction hash.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getTransaction($tx){
        // evxProfiler::checkpoint('getTransaction START [hash=' . $tx . ']');
        $cursor = $this->dbs['transactions']->find(array("hash" => $tx));
        $result = $cursor->hasNext() ? $cursor->getNext() : false;
        if($result){
            $receipt = isset($result['receipt']) ? $result['receipt'] : false;
            unset($result["_id"]);
            $result['gasLimit'] = $result['gas'];
            unset($result["gas"]);
            $result['gasUsed'] = $receipt ? $receipt['gasUsed'] : 0;
            $result['success'] = (($result['gasUsed'] < $result['gasLimit']) || ($receipt && !empty($receipt['logs'])));
        }
        // evxProfiler::checkpoint('getTransaction FINISH [hash=' . $tx . ']');
        return $result;
    }


    /**
     * Returns list of transfers in specified transaction.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getOperations($tx, $type = FALSE){
        // evxProfiler::checkpoint('getOperations START [hash=' . $tx . ']');
        $search = array("transactionHash" => $tx);
        if($type){
            $search['type'] = $type;
        }
        $cursor = $this->dbs['operations']->find($search)->sort(array('priority' => 1));
        $result = array();
        while($cursor->hasNext()){
            $res = $cursor->getNext();
            unset($res["_id"]);
            $res["success"] = true;
            $result[] = $res;
        }
        // evxProfiler::checkpoint('getOperations FINISH [hash=' . $tx . ']');
        return $result;
    }

    /**
     * Returns list of transfers in specified transaction.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getTransfers($tx){
        return $this->getOperations($tx, 'transfer');
    }

    /**
     * Returns list of issuances in specified transaction.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getIssuances($tx){
        return $this->getOperations($tx, array('$in' => array('issuance', 'burn', 'mint')));
    }

    /**
     * Returns list of known tokens.
     *
     * @param bool  $updateCache  Update cache from DB if true
     * @return array
     */
    public function getTokens($updateCache = false){
        $aResult = $updateCache ? false : $this->oCache->get('tokens', false, true);
        if(false === $aResult){
            $cursor = $this->dbs['tokens']->find()->sort(array("transfersCount" => -1));
            $aResult = array();
            foreach($cursor as $aToken){
                $address = $aToken["address"];
                unset($aToken["_id"]);
                $aResult[$address] = $aToken;
                $aResult[$address] += $this->getTokenTotalInOut($address);
                $aResult[$address]['holdersCount'] = $this->getTokenHoldersCount($address);
                if(isset($this->aSettings['client']) && isset($this->aSettings['client']['tokens'])){
                    $aClientTokens = $this->aSettings['client']['tokens'];
                    if(isset($aClientTokens[$address])){
                        $aResult[$address] = array_merge($aResult[$address], $aClientTokens[$address]);
                    }
                }
            }
            $this->oCache->save('tokens', $aResult);
        }
        return $aResult;
    }


    public function getTokenHoldersCount($address, $useFilter = TRUE){
        $search = array('contract' => $address, 'balance' => array('$gt' => 0));
        if($useFilter && $this->filter){
            $search = array(
                '$and' => array(
                    $search,
                    array('address' => array('$regex' => $this->filter)),
                )
            );
        }
        return $this->dbs['balances']->count($search);
    }

    /**
     * Returns list of token holders.
     *
     * @param string $address
     * @param int $limit
     * @return array
     */
    public function getTokenHolders($address, $limit = FALSE, $offset = FALSE){
        $result = array();
        $token = $this->getToken($address);
        if($token){
            $search = array('contract' => $address, 'balance' => array('$gt' => 0));
            if($this->filter){
                $search = array(
                    '$and' => array(
                        $search,
                        array('address' => array('$regex' => $this->filter)),
                    )
                );
            }
            $cursor = $this->dbs['balances']->find($search)->sort(array('balance' => -1));
            if((FALSE !== $offset) && $offset){
                $cursor = $cursor->skip($offset);
            }
            if((FALSE !== $limit) && $limit){
                $cursor = $cursor->limit($limit);
            }
            if($cursor){
                $total = 0;
                foreach($cursor as $balance){
                    $total += floatval($balance['balance']);
                }
                if($total > 0){
                    if(isset($token['totalSupply']) && ($total < $token['totalSupply'])){
                        $total = $token['totalSupply'];
                    }
                    foreach($cursor as $balance){
                        $result[] = array(
                            'address' => $balance['address'],
                            'balance' => floatval($balance['balance']),
                            'share' => round((floatval($balance['balance']) / $total) * 100, 2)
                        );
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Returns token data by contract address.
     *
     * @param string  $address  Token contract address
     * @return array
     */
    public function getToken($address){
        $cache = 'token-' . $address;
        $result = $this->oCache->get($cache, false, true, 30);
        if(FALSE === $result){
            $aTokens = $this->getTokens();
            $result = isset($aTokens[$address]) ? $aTokens[$address] : false;
            if($result){
                unset($result["_id"]);
                if(!isset($result['decimals']) || !intval($result['decimals'])){
                    $result['decimals'] = 0;
                    if(isset($result['totalSupply']) && ((float)$result['totalSupply'] > 1e+18)){
                        $result['decimals'] = 18;
                        $result['estimatedDecimals'] = true;
                    }
                }
                if(!isset($result['symbol'])){
                    $result['symbol'] = "";
                }
                if(isset($result['txsCount'])){
                    $result['txsCount'] = (int)$result['txsCount'] + 1;
                }
                $result += array(
                    'transfersCount' => $this->getContractOperationCount('transfer', $address, FALSE),
                    'issuancesCount' => $this->getContractOperationCount(array('$in' => array('issuance', 'burn', 'mint')), $address, FALSE),
                    'holdersCount' => ''
                );
                if(isset($this->aSettings['client']) && isset($this->aSettings['client']['tokens'])){
                    $aClientTokens = $this->aSettings['client']['tokens'];
                    if(isset($aClientTokens[$address])){
                        $aClientToken = $aClientTokens[$address];
                        if(isset($aClientToken['name'])){
                            $result['name'] = $aClientToken['name'];
                        }
                        if(isset($aClientToken['symbol'])){
                            $result['symbol'] = $aClientToken['symbol'];
                        }
                    }
                }
                $price = $this->getTokenPrice($address);
                if(is_array($price)){
                    $price['currency'] = 'USD';
                }
                $result['price'] = $price ? $price : false;
                $this->oCache->save($cache, $result);
            }
        }
        return $result;
    }

    /**
     * Returns contract data by contract address.
     *
     * @param string $address
     * @return array
     */
    public function getContract($address){
        // evxProfiler::checkpoint('getContract START [address=' . $address . ']');
        $cursor = $this->dbs['contracts']->find(array("address" => $address));
        $result = $cursor->hasNext() ? $cursor->getNext() : false;
        if($result){
            unset($result["_id"]);
            $result['txsCount'] = $this->dbs['transactions']->count(array("to" => $address)) + 1;
            if($this->isChainyAddress($address)){
                $result['isChainy'] = true;
            }
        }
        // evxProfiler::checkpoint('getContract FINISH [address=' . $address . ']');
        return $result;
    }

    /**
     * Returns total number of token operations for the address.
     *
     * @param string $address  Contract address
     * @return int
     */
    public function countOperations($address, $useFilter = TRUE){
        $result = 0;
        $token = $this->getToken($address);
        if($token){
            $search = array('contract' => $address);
        }else{
            $search = array(
                '$or' => array(
                    array("from"    => $address),
                    array("to"      => $address),
                    array('address' => $address)
                )
            );
            $search['type'] = array('$in' => array('transfer', 'issuance', 'burn', 'mint'));
        }
        if($useFilter && $this->filter){
            $search = array(
                '$and' => array(
                    $search,
                    array(
                        '$or' => array(
                            array('from'                => array('$regex' => $this->filter)),
                            array('to'                  => array('$regex' => $this->filter)),
                            array('address'             => array('$regex' => $this->filter)),
                            array('transactionHash'     => array('$regex' => $this->filter)),
                        )
                    )
                )
            );
        }
        $result = $this->dbs['operations']->count($search);
        return $result;
    }


    /**
     * Returns total number of transactions for the address (incoming, outoming, contract creation).
     *
     * @param string $address  Contract address
     * @return int
     */
    public function countTransactions($address){
        $result = 0;
        $result = $this
            ->dbs['transactions']
            ->count(array('$or' => array(array('from' => $address), array('to' => $address))));
        if($this->getContract($address)){
            $result++; // One for contract creation
        }
        return $result;
    }

    /**
     * Returns list of contract transfers.
     *
     * @param string $address  Contract address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getContractTransfers($address, $limit = 10, $offset = FALSE){
        return $this->getContractOperation('transfer', $address, $limit, $offset);
    }

    /**
     * Returns list of contract issuances.
     *
     * @param string $address  Contract address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getContractIssuances($address, $limit = 10, $offset = FALSE){
        return $this->getContractOperation(array('$in' => array('issuance', 'burn', 'mint')), $address, $limit, $offset);
    }

    /**
     * Returns last known mined block number.
     *
     * @return int
     */
    public function getLastBlock(){
        // evxProfiler::checkpoint('getLastBlock START');
        $cursor = $this->dbs['blocks']->find(array(), array('number' => true))->sort(array('number' => -1))->limit(1);
        $block = $cursor->getNext();
        // evxProfiler::checkpoint('getLastBlock FINISH');
        return $block && isset($block['number']) ? $block['number'] : false;
    }

    /**
     * Returns address token balances.
     *
     * @param string $address  Address
     * @param bool $withZero   Returns zero balances if true
     * @return array
     */
    public function getAddressBalances($address, $withZero = true){
        $search = array("address" => $address);
        if(!$withZero){
            $search['balance'] = array('$gt' => 0);
        }
        $search['totalIn'] = array('$gt' => 0);
        $cursor = $this->dbs['balances']->find($search, array('contract', 'balance', 'totalIn', 'totalOut'));
        $result = array();
        foreach($cursor as $balance){
            unset($balance["_id"]);
            $result[] = $balance;
        }
        return $result;
    }

    /**
     * Returns list of transfers made by specified address.
     *
     * @param string $address  Address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getLastTransfers(array $options = array()){
        $search = array();
        if(!isset($options['type'])){
            $search['type'] = 'transfer';
        }else{
            if(FALSE !== $options['type']){
                $search['type'] = $options['type'];
            }
        }
        if(isset($options['address']) && !isset($options['history'])){
            $search['contract'] = $options['address'];
        }
        if(isset($options['address']) && isset($options['history'])){
            $search['$or'] = array(array('from' => $options['address']), array('to' => $options['address']), array('address' => $options['address']));
        }

        if(isset($options['token']) && isset($options['history'])){
            $search['contract'] = $options['token'];
        }

        $sort = array("timestamp" => -1);

        if(isset($options['timestamp']) && ($options['timestamp'] > 0)){
            $search['timestamp'] = array('$gt' => $options['timestamp']);
        }
        $cursor = $this->dbs['operations']
            ->find($search)
            ->sort($sort);

        if(isset($options['limit'])){
            $cursor = $cursor->limit((int)$options['limit']);
        }

        $result = array();
        foreach($cursor as $transfer){
            $transfer['token'] = $this->getToken($transfer['contract']);
            unset($transfer["_id"]);
            $result[] = $transfer;
        }
        return $result;
    }

    /**
     * Returns list of transfers made by specified address.
     *
     * @param string $address  Address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getAddressOperations($address, $limit = 10, $offset = FALSE, array $aTypes = array('transfer', 'issuance', 'burn', 'mint')){
        $search = array(
            '$or' => array(
                array("from"    => $address),
                array("to"      => $address),
                array('address' => $address)
            )
        );
        if($this->filter){
            $search = array(
                '$and' => array(
                    $search,
                    array(
                        '$or' => array(
                            array('from'                => array('$regex' => $this->filter)),
                            array('to'                  => array('$regex' => $this->filter)),
                            array('address'             => array('$regex' => $this->filter)),
                            array('transactionHash'     => array('$regex' => $this->filter)),
                        )
                    )
                )
            );
        }
        $search['type'] = array('$in' => $aTypes);

        $cursor = $this->dbs['operations']->find($search)->sort(array("timestamp" => -1));
        if($offset){
            $cursor = $cursor->skip($offset);
        }
        if($limit){
            $cursor = $cursor->limit($limit);
        }
        $result = array();
        foreach($cursor as $transfer){
            unset($transfer["_id"]);
            $result[] = $transfer;
        }
        return $result;
    }

    /**
     * Returns data of operations made by specified address for downloading in CSV format.
     *
     * @param string $address  Address
     * @param string $type     Operations type
     * @return array
     */
    public function getAddressOperationsCSV($address, $type = 'transfer'){
        $limit = 1000;

        $cache = 'address_operations_csv-' . $address . '-' . $limit;
        $result = $this->oCache->get($cache, false, true, 600);
        if(FALSE === $result){
            $cr = "\r\n";
            $spl = ";";
            $result = 'date;txhash;from;to;token-name;token-address;value;symbol' . $cr;

            $options = array(
                'address' => $address,
                'type' => $type,
                'limit' => $limit
            );
            $aTokens = array();
            $addTokenInfo = true;
            $isContract = $this->getContract($address);
            if($isContract){
                $addTokenInfo = false;
            }
            $ten = Decimal::create(10);
            $dec = false;
            $tokenName = '';
            $tokenSymbol = '';
            $isToken = $this->getToken($address);
            if($isToken){
                $operations = $this->getLastTransfers($options);
                $dec = Decimal::create($isToken['decimals']);
                $tokenName = isset($isToken['name']) ? $isToken['name'] : '';
                $tokenSymbol = isset($isToken['symbol']) ? $isToken['symbol'] : '';
            }else{
                $operations = $this->getAddressOperations($address, $limit, FALSE, array('transfer'));
            }
            $aTokenInfo = array();
            foreach($operations as $record){
                $date = date("Y-m-d H:i:s", $record['timestamp']);
                $hash = $record['transactionHash'];
                $from = isset($record['from']) ? $record['from'] : '';
                $to = isset($record['to']) ? $record['to'] : '';
                $tokenAddress = '';
                if($addTokenInfo && isset($record['contract'])){
                    $tokenName = '';
                    $tokenSymbol = '';
                    $contract = $record['contract'];
                    $token = isset($aTokenInfo[$contract]) ? $aTokenInfo[$contract] : $this->getToken($contract);
                    if($token){
                        $tokenName = isset($token['name']) ? $token['name'] : '';
                        $tokenSymbol = isset($token['symbol']) ? $token['symbol'] : '';
                        $tokenAddress = isset($token['address']) ? $token['address'] : '';
                        if(isset($token['decimals'])) $dec = Decimal::create($token['decimals']);
                        if(!isset($aTokenInfo[$contract])) $aTokenInfo[$contract] = $token;
                    }
                }
                $value = $record['value'];
                if($dec){
                    $value = Decimal::create($record['value']);
                    $value = $value->div($ten->pow($dec), 4);
                }
                $result .= $date . $spl . $hash . $spl . $from . $spl . $to . $spl . $tokenName . $spl . $tokenAddress . $spl . $value . $spl . $tokenSymbol . $cr;
            }
            $this->oCache->save($cache, $result);
        }
        return $result;
    }

    /**
     * Returns top tokens list.
     *
     * @todo: count number of transactions with "transfer" operation
     * @param int $limit   Maximum records number
     * @param int $period  Days from now
     * @return array
     */
    public function getTopTokens($limit = 10, $period = 30){
        $cache = 'top_tokens-' . $period . '-' . $limit;
        $result = $this->oCache->get($cache, false, true, 24 * 3600);
        if(FALSE === $result){
            $result = array();
            $dbData = $this->dbs['operations']->aggregate(
                array(
                    array('$match' => array("timestamp" => array('$gt' => time() - $period * 24 * 3600))),
                    array(
                        '$group' => array(
                            "_id" => '$contract',
                            'cnt' => array('$sum' => 1)
                        )
                    ),
                    array('$sort' => array('cnt' => -1)),
                    array('$limit' => $limit)
                )
            );
            if(is_array($dbData) && !empty($dbData['result'])){
                foreach($dbData['result'] as $token){
                    $oToken = $this->getToken($token['_id']);
                    $oToken['opCount'] = $token['cnt'];
                    unset($oToken['checked']);
                    $result[] = $oToken;
                }
                $this->oCache->save($cache, $result);
            }
        }
        return $result;
    }

    /**
     * Returns top tokens list by current volume.
     *
     * @todo: count number of transactions with "transfer" operation
     * @param int $limit   Maximum records number
     * @return array
     */
    public function getTopTokensByPeriodVolume($limit = 10, $period = 30){
        set_time_limit(0);
        $cache = 'top_tokens-by-period-volume-' . $limit . '-' . $period;
        $result = $this->oCache->get($cache, false, true, 24 * 3600);
        $today = date("Y-m-d");
        if(FALSE === $result){
            $aTokens = $this->getTokens();
            $result = array();
            foreach($aTokens as $aToken){
                $aPrice = $this->getTokenPrice($aToken['address']);
                if($aPrice && $aToken['totalSupply']){
                    $aCorrectedToken = $this->getToken($aToken['address']);
                    if(isset($aCorrectedToken['name'])){
                        $aToken['name'] = $aCorrectedToken['name'];
                    }

                    $aMatch = array(
                        "contract" => $aToken['address'],
                        'type' => array('$in' => array('transfer', 'issuance', 'burn', 'mint')),
                        "timestamp" => array('$gt' => time() - $period * 24 * 3600),
                    );
                    $dbData = $this->dbs['operations']->aggregate(
                        array(
                            array('$match' => $aMatch),
                            array(
                                '$group' => array(
                                    "_id" => array(
                                        "year"  => array('$year' => array('$add' => array(new MongoDate(0), array('$multiply' => array('$timestamp', 1000))))),
                                        "month"  => array('$month' => array('$add' => array(new MongoDate(0), array('$multiply' => array('$timestamp', 1000))))),
                                        "day"  => array( '$dayOfMonth' => array('$add' => array(new MongoDate(0), array('$multiply' => array('$timestamp', 1000))))),
                                    ),
                                    'ts' =>  array('$first' => '$timestamp'),
                                    'sum' => array('$sum' => '$intValue')
                                )
                            ),
                            array('$sort' => array('ts' => -1)),
                        )
                    );

                    $aToken['volume'] = 0;
                    if($dbData && $dbData['result']){
                        $aData = $dbData['result'];
                        foreach($aData as $aItem){
                            $date = $aItem['_id']['year'] . '-' . str_pad($aItem['_id']['month'], 2, '0', STR_PAD_LEFT) . '-' . str_pad($aItem['_id']['day'], 2, '0', STR_PAD_LEFT);
                            if($date === $today){
                                continue;
                            }
                            $rate = $this-> _getAverageRateByDate($aToken['address'], $date);
                            $aToken['volume'] += ($aItem['sum'] / pow(10, $aToken['decimals'])) * $rate;
                        }
                    }
                    $result[] = $aToken;
                }
                usort($result, array($this, '_sortByVolume'));

                $res = [];
                foreach($result as $i => $item){
                    if($i < $limit){
                        $res[] = $item;
                    }
                }
                $result = $res;
            }
            $this->oCache->save($cache, $result);
        }
        return $result;
    }

    /**
     * Returns top tokens list by current volume.
     *
     * @todo: count number of transactions with "transfer" operation
     * @param int $limit   Maximum records number
     * @return array
     */
    public function getTopTokensByCurrentVolume($limit = 10){
        $cache = 'top_tokens-by-current-volume-' . $limit;
        $result = $this->oCache->get($cache, false, true, 600);
        if(FALSE === $result){
            $aTokens = $this->getTokens();
            $result = array();
            foreach($aTokens as $aToken){
                $aPrice = $this->getTokenPrice($aToken['address']);
                if($aPrice && $aToken['totalSupply']){
                    $aCorrectedToken = $this->getToken($aToken['address']);
                    if(isset($aCorrectedToken['name'])){
                        $aToken['name'] = $aCorrectedToken['name'];
                    }
                    $aToken['volume'] = $aPrice['rate'] * $aToken['totalSupply'] / pow(10, $aToken['decimals']);
                    $result[] = $aToken;
                }
                usort($result, array($this, '_sortByVolume'));
                $res = [];
                foreach($result as $i => $item){
                    if($i < $limit){
                        $res[] = $item;
                    }
                }
                $result = $res;
            }
            $this->oCache->save($cache, $result);
        }
        return $result;
    }

    protected function _sortByVolume($a, $b){
        return ($a['volume'] == $b['volume']) ? 0 : (($a['volume'] > $b['volume']) ? -1 : 1);
    }

    /**
     * Returns transactions grouped by days.
     *
     * @param int $period      Days from now
     * @param string $address  Address
     * @return array
     */
    public function getTokenHistoryGrouped($period = 30, $address = FALSE){
        $cache = 'token_history_grouped-' . ($address ? ($address . '-') : '') . $period;
        $result = $this->oCache->get($cache, false, true, 600);
        if(FALSE === $result){
            // Chainy
            if($address && ($address == self::ADDRESS_CHAINY)){
                return $this->getChainyTokenHistoryGrouped($period);
            }

            $tsStart = gmmktime(0, 0, 0, date('n'), date('j') - $period, date('Y'));
            $aMatch = array("timestamp" => array('$gt' => $tsStart));
            if($address) $aMatch["contract"] = $address;
            $result = array();
            $dbData = $this->dbs['operations']->aggregate(
                array(
                    array('$match' => $aMatch),
                    array(
                        '$group' => array(
                            "_id" => array(
                                "year"  => array('$year' => array('$add' => array(new MongoDate(0), array('$multiply' => array('$timestamp', 1000))))),
                                "month"  => array('$month' => array('$add' => array(new MongoDate(0), array('$multiply' => array('$timestamp', 1000))))),
                                "day"  => array( '$dayOfMonth' => array('$add' => array(new MongoDate(0), array('$multiply' => array('$timestamp', 1000))))),
                            ),
                            'ts' =>  array('$first' => '$timestamp'),
                            'cnt' => array('$sum' => 1)
                        )
                    ),
                    array('$sort' => array('ts' => -1)),
                    //array('$limit' => 10)
                )
            );
            if(is_array($dbData) && !empty($dbData['result'])){
                $result = $dbData['result'];
                $this->oCache->save($cache, $result);
            }
        }
        return $result;
    }

    public function checkAPIKey($key){
        return isset($this->aSettings['apiKeys']) && isset($this->aSettings['apiKeys'][$key]);
    }

    public function getAPIKeyDefaults($key, $option = FALSE){
        $res = FALSE;
        if($this->checkAPIKey($key)){
            if(is_array($this->aSettings['apiKeys'][$key])){
                if(FALSE === $option){
                    $res = $this->aSettings['apiKeys'][$key];
                }else if(isset($this->aSettings['apiKeys'][$key][$option])){
                    $res = $this->aSettings['apiKeys'][$key][$option];
                }
            }
        }
        return $res;
    }

    /**
     * Returns contract operation data.
     *
     * @param string $type     Operation type
     * @param string $address  Contract address
     * @return array
     */
    protected function getContractOperationCount($type, $address, $useFilter = TRUE){
        $search = array("contract" => $address, 'type' => $type);
        if($useFilter && $this->filter){
            $search['$or'] = array(
                array('from'                => array('$regex' => $this->filter)),
                array('to'                  => array('$regex' => $this->filter)),
                array('address'             => array('$regex' => $this->filter)),
                array('transactionHash'     => array('$regex' => $this->filter))
            );
        }
        $cursor = $this->dbs['operations']
            ->find($search)
            ->sort(array("timestamp" => -1));
        return $cursor ? $cursor->count() : 0;
    }

    /**
     * Returns contract operation data.
     *
     * @param string $type     Operation type
     * @param string $address  Contract address
     * @param string $limit    Maximum number of records
     * @return array
     */
    protected function getContractOperation($type, $address, $limit, $offset = FALSE){
        $search = array("contract" => $address, 'type' => $type);
        if($this->filter){
            $search['$or'] = array(
                array('from'                => array('$regex' => $this->filter)),
                array('to'                  => array('$regex' => $this->filter)),
                array('address'             => array('$regex' => $this->filter)),
                array('transactionHash'     => array('$regex' => $this->filter))
            );
        }
        $cursor = $this->dbs['operations']
            ->find($search)
            ->sort(array("timestamp" => -1));
        if($offset){
            $cursor = $cursor->skip($offset);
        }
        if($limit){
            $cursor = $cursor->limit($limit);
        }
        $result = array();
        $fetches = 0;
        foreach($cursor as $transfer){
            unset($transfer["_id"]);
            $result[] = $transfer;
            $fetches++;
        }
        return $result;
    }

    /**
     * Returns last Chainy transactions.
     *
     * @param  int $limit  Maximum number of records
     * @return array
     */
    protected function getChainyTransactions($limit = 10, $offset = FALSE){
        $result = array();
        $search = array('to' => self::ADDRESS_CHAINY);
        if($this->filter){
            $search = array(
                '$and' => array(
                    $search,
                    array('hash' => array('$regex' => $this->filter)),
                )
            );
        }
        $cursor = $this->dbs['transactions']->find($search)->sort(array("timestamp" => -1));
        if($offset){
            $cursor = $cursor->skip($offset);
        }
        if($limit){
            $cursor = $cursor->limit($limit);
        }
        foreach($cursor as $tx){
            if(!empty($tx['receipt']['logs'])){
                $link = substr($tx['receipt']['logs'][0]['data'], 194);
                $link = preg_replace("/0+$/", "", $link);
                if((strlen($link) % 2) !== 0){
                    $link = $link . '0';
                }
                $result[] = array(
                    'hash' => $tx['hash'],
                    'timestamp' => $tx['timestamp'],
                    'input' => $tx['input'],
                    'link' => $link,
                );
            }
        }
        return $result;
    }

    /**
     * Returns Chainy transactions grouped by days.
     *
     * @param  int $period  Number of days
     * @return array
     */
    protected function getChainyTokenHistoryGrouped($period = 30){
        $result = array();
        $aMatch = array(
            "timestamp" => array('$gt' => time() - $period * 24 * 3600),
            "to" => self::ADDRESS_CHAINY
        );
        $dbData = $this->dbs['transactions']->aggregate(
            array(
                array('$match' => $aMatch),
                array(
                    '$group' => array(
                        "_id" => array(
                            "year"  => array('$year' => array('$add' => array(new MongoDate(0), array('$multiply' => array('$timestamp', 1000))))),
                            "month"  => array('$month' => array('$add' => array(new MongoDate(0), array('$multiply' => array('$timestamp', 1000))))),
                            "day"  => array( '$dayOfMonth' => array('$add' => array(new MongoDate(0), array('$multiply' => array('$timestamp', 1000))))),
                        ),
                        'ts' =>  array('$first' => '$timestamp'),
                        'cnt' => array('$sum' => 1)
                    )
                ),
                array('$sort' => array('ts' => -1))
            )
        );
        if(is_array($dbData) && !empty($dbData['result'])){
            $result = $dbData['result'];
        }
        return $result;
    }

    /**
     * Returns total number of Chainy operations for the address.
     *
     * @return int
     */
    public function countChainy($useFilter = TRUE){
        $search = array('to' => self::ADDRESS_CHAINY);
        if($useFilter && $this->filter){
            $search = array(
                '$and' => array(
                    $search,
                    array('hash' => array('$regex' => $this->filter)),
                )
            );
        }
        $result = $this->dbs['transactions']->count($search);
        return $result;
    }

    public function getETHPrice(){
        $result = false;
        $eth = $this->getTokenPrice('0x0000000000000000000000000000000000000000');
        if(false !== $eth){
            $result = $eth;
        }
        return $result;
    }

    public function getTokenPrice($address, $updateCache = FALSE){
        $result = false;
        $cache = 'rates';
        $rates = $this->oCache->get($cache, false, true);
        if($updateCache || (((FALSE === $rates) || (is_array($rates) && !isset($rates[$address]))) && isset($this->aSettings['updateRates']) && (FALSE !== array_search($address, $this->aSettings['updateRates'])))){
            if(!is_array($rates)){
                $rates = array();
            }
            if(isset($this->aSettings['currency'])){
                $method = 'getCurrencyCurrent';
                $params = array($address, 'USD');
                $result = $this->_jsonrpcall($this->aSettings['currency'], $method, $params);
                if($result){
                    unset($result['code_from']);
                    unset($result['code_to']);
                    unset($result['bid']);
                    $rates[$address] = $result;
                    $this->oCache->save($cache, $rates);
                }
            }
        }
        if(is_array($rates) && isset($rates[$address])){
            $result = $rates[$address];
        }
        return $result;
    }

    public function getTokenPriceHistory($address, $period = 0, $type = 'hourly', $updateCache = FALSE){
        $result = false;
        $rates = array();
        $cache = 'rates-history-' . /*($period > 0 ? ('period-' . $period . '-') : '' ) . ($type != 'hourly' ? $type . '-' : '') .*/ $address;
        $result = $this->oCache->get($cache, false, true);
        if($updateCache || ((FALSE === $result) && isset($this->aSettings['updateRates']) && (FALSE !== array_search($address, $this->aSettings['updateRates'])))){
            if(isset($this->aSettings['currency'])){
                $method = 'getCurrencyHistory';
                $params = array($address, 'USD');
                $result = $this->_jsonrpcall($this->aSettings['currency'], $method, $params);
                $this->oCache->save($cache, $result);
            }
        }
        if($result){
            $aPriceHistory = array();
            if($period){
                $tsStart = gmmktime(0, 0, 0, date('n'), date('j') - $period, date('Y'));
                for($i = 0; $i < count($result); $i++){
                    if($result[$i]['ts'] < $tsStart){
                        continue;
                    }
                    $aPriceHistory[] = $result[$i];
                }
            }else{
                $aPriceHistory = $result;
            }
            if($type == 'daily'){
                $aPriceHistoryDaily = array();
                $aDailyRecord = array();
                $curDate = '';
                for($i = 0; $i < count($aPriceHistory); $i++){
                    $firstRecord = false;
                    $lastRecord = false;
                    if(!$curDate || ($curDate != $aPriceHistory[$i]['date'])){
                        $aDailyRecord = $aPriceHistory[$i];
                        $firstRecord = true;
                    }else{
                        if(($i == (count($aPriceHistory) - 1)) || ($aPriceHistory[$i]['date'] != $aPriceHistory[$i + 1]['date'])){
                            $lastRecord = true;
                        }
                        if($lastRecord){
                            $aDailyRecord['close'] = $aPriceHistory[$i]['close'];
                        }
                    }
                    if(!$firstRecord){
                        if($aPriceHistory[$i]['high'] > $aDailyRecord['high']){
                            $aDailyRecord['high'] = $aPriceHistory[$i]['high'];
                        }
                        if($aPriceHistory[$i]['low'] < $aDailyRecord['low']){
                            $aDailyRecord['low'] = $aPriceHistory[$i]['low'];
                        }
                    }
                    if($lastRecord){
                        $aPriceHistoryDaily[] = $aDailyRecord;
                    }
                    $curDate = $aPriceHistory[$i]['date'];
                }
            }
            $rates[$address] = ($type == 'daily' ? $aPriceHistoryDaily : $aPriceHistory);
        }
        if(is_array($rates) && isset($rates[$address])){
            $result = $rates[$address];
        }
        return $result;
    }

    protected function getTokenPriceCurrent($address){
        $this->_getRateByDate($address, date("Y-m-d"));
    }

    public function getTokenPriceHistoryGrouped($address, $period = 365, $type = 'daily', $updateCache = FALSE){
        $aResult = array();

        $aHistoryCount = $this->getTokenHistoryGrouped($period, $address);
        $aResult['countTxs'] = $aHistoryCount;
        unset($aHistoryCount);

        $aHistoryPrices = $this->getTokenPriceHistory($address, $period, $type);
        $aResult['prices'] = $aHistoryPrices;
        unset($aHistory);

        //$aCurrentData = $this->getTokenPriceCurrent($address);
        $aResult['current'] = $this->_getRateByDate($address, date("Y-m-d"));
        //unset($aCurrentData);

        return $aResult;
    }

    protected function _getAverageRateByDate($address, $date){
        $aHistory = $this->getTokenPriceHistory($address);
        $result = 0;
        $datePos = array_search($date, array_column($aHistory, 'date'));
        if(FALSE !== $datePos){
            $max = max($datePos + 24, count($aHistory));
            $i = 0;
            $sum = 0;
            for($index = $datePos; $index < $max; $index++){
                $i++;
                $sum += ($aHistory[$index]['open'] + $aHistory[$index]['close']) / 2;
            }
            $result = round($sum / $i, 2);
        }
        return $result;
    }

    protected function _getRateByTimestamp($address, $timestamp){
        $result = 0;
        $aHistory = $this->getTokenPriceHistory($address);
        if(is_array($aHistory)){
            foreach($aHistory as $aRecord){
                if(isset($aRecord['open'])){
                    $ts = $aRecord['ts'];
                    if($ts <= $timestamp){
                        $result = $aRecord['open'];
                    }else{
                        break;
                    }
                }
            }
        }
        return $result;
    }

    protected function _getRateByDate($address, $date){
        $result = 0;
        $aHistory = $this->getTokenPriceHistory($address);
        $aHistoryByDate = array();
        if(is_array($aHistory)){
            foreach($aHistory as $aRecord){
                if(isset($aRecord['open'])){
                    $date = $aRecord['date'];
                    if(isset($aHistoryByDate[$date])){
                        continue;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * JSON RPC request implementation.
     *
     * @param string $method  Method name
     * @param array $params   Parameters
     * @return array
     */
    protected function _callRPC($method, $params = array()){
        if(!isset($this->aSettings['ethereum'])){
            throw new Exception("Ethereum configuration not found");
        }
        return $this->_jsonrpcall($this->aSettings['ethereum'], $method, $params);
    }

    protected function _jsonrpcall($service, $method, $params = array()){
        $data = array(
            'jsonrpc' => "2.0",
            'id'      => time(),
            'method'  => $method,
            'params'  => $params
        );
        $result = false;
        $json = json_encode($data);
        $ch = curl_init($service);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json))
        );
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json );
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $rjson = curl_exec($ch);
        if($rjson && (is_string($rjson)) && ('{' === $rjson[0])){
            $json = json_decode($rjson, JSON_OBJECT_AS_ARRAY);
            if(isset($json["result"])){
                $result = $json["result"];
            }
        }
        return $result;
    }

    public function searchToken($token){
        $result = array('results' => array(), 'total' => 0);
        $found = array();
        $aTokens = $this->getTokens();
        $aTokens['0xf3763c30dd6986b53402d41a8552b8f7f6a6089b'] = array(
            'name' => 'Chainy',
            'symbol' => false,
            'txsCount' => 99999
        );
        if(isset($this->aSettings['client']) && isset($this->aSettings['client']['tokens'])){
            $aClientTokens = $this->aSettings['client']['tokens'];
            foreach($aClientTokens as $address => $aClientToken){
                if(isset($aTokens[$address])){
                    if(isset($aClientToken['name'])){
                        $aTokens[$address]['name'] = $aClientToken['name'];
                    }
                    if(isset($aClientToken['symbol'])){
                        $aTokens[$address]['symbol'] = $aClientToken['symbol'];
                    }
                }
            }
        }
        foreach($aTokens as $address => $aToken){
            $search = strtolower($token);
            if((strpos($address, $search) !== FALSE) || (!empty($aToken['name']) && (strpos(strtolower($aToken['name']), $search) !== FALSE)) || (!empty($aToken['symbol']) && (strpos(strtolower($aToken['symbol']), $search) !== FALSE))){
                $aToken['address'] = $address;
                $found[] = $aToken;
            }
        }
        uasort($found, array($this, 'sortTokensByTxsCount'));
        $i = 0;
        foreach($found as $aToken){
            if($i < 6){
                $aToken += array('name' => '', 'symbol' => '');
                $result['results'][] = array($aToken['name'], $aToken['symbol'], $aToken['address']);
            }
            $i++;
        }
        $result['total'] = $i;
        $result['search'] = $token;
        return $result;
    }

    public function sortTokensByTxsCount($a, $b) {
        if(!isset($a['txsCount'])){
            $a['txsCount'] = 0;
        }
        if(!isset($b['txsCount'])){
            $b['txsCount'] = 0;
        }
        if($a['txsCount'] == $b['txsCount']){
            return 0;
        }
        return ($a['txsCount'] < $b['txsCount']) ? 1 : -1;
    }

    public function getActiveNotes(){
        $result = array();
        if(isset($this->aSettings['adv'])){
            $all = $this->aSettings['adv'];
            foreach($all as $one){
                if(isset($one['activeTill'])){
                    if($one['activeTill'] <= time()){
                        continue;
                    }
                }
                $one['link'] = urlencode($one['link']);
                $one['hasNext'] = (count($all) > 1);
                $result[] = $one;
            }
        }
        return $result;
    }

}

/**
* Provides functionality for array_column() to projects using PHP earlier than
* version 5.5.
* @copyright (c) 2015 WinterSilence (http://github.com/WinterSilence)
* @license MIT
*/
if (!function_exists('array_column')) {
    /**
     * Returns an array of values representing a single column from the input
     * array.
     * @param array $array A multi-dimensional array from which to pull a
     *     column of values.
     * @param mixed $columnKey The column of values to return. This value may
     *     be the integer key of the column you wish to retrieve, or it may be
     *     the string key name for an associative array. It may also be NULL to
     *     return complete arrays (useful together with index_key to reindex
     *     the array).
     * @param mixed $indexKey The column to use as the index/keys for the
     *     returned array. This value may be the integer key of the column, or
     *     it may be the string key name.
     * @return array
     */
    function array_column(array $array, $columnKey, $indexKey = null)
    {
        $result = array();
        foreach ($array as $subArray) {
            if (!is_array($subArray)) {
                continue;
            } elseif (is_null($indexKey) && array_key_exists($columnKey, $subArray)) {
                $result[] = $subArray[$columnKey];
            } elseif (array_key_exists($indexKey, $subArray)) {
                if (is_null($columnKey)) {
                    $result[$subArray[$indexKey]] = $subArray;
                } elseif (array_key_exists($columnKey, $subArray)) {
                    $result[$subArray[$indexKey]] = $subArray[$columnKey];
                }
            }
        }
        return $result;
    }
}
