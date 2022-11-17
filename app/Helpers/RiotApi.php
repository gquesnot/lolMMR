<?php

namespace App\Helpers;

use App\Models\Champion;
use App\Models\ItemSummonerMatch;
use App\Models\Map;
use App\Models\Matche;
use App\Models\Mode;
use App\Models\Queue;
use App\Models\Summoner;
use App\Models\SummonerMatch;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class  RiotApi
{
    protected Client $client;

    public ?Collection $modes = null;
    public ?Collection $maps = null;
    public ?Collection $queues = null;


    public string $api_key;


    public function __construct()
    {
        $this->api_key = config('lol.api_key');
        $this->client = new Client();
        $this->modes = Mode::select(['id', 'name'])->get();
        $this->maps = Map::pluck('id');
        $this->queues = Queue::pluck('id');
    }



//    public function getSummonerNameByPuuid(string $puuid): ?string
//    {
//        $summoner = SummonerApi::wherePuuid($puuid)->whereAccountId($this->getAccount()->id)->with('summoner')->first()?->summoner;
//        if ($summoner == null) {
//            return $this->getSummonerByPuuid($puuid)?->name;
//        }
//        return $summoner->name;
//    }


    public function createOrGetTmpSummoner($data): Summoner
    {
        $summoner = Summoner::wherePuuid($data->puuid)->first();
        if (!$summoner) {
            $summoner = Summoner::firstOrCreate([
                'name' => $data->summonerName,
                'summoner_level' => intval($data->summonerLevel),
                'profile_icon_id' => intval($data->profileIcon),
                'complete' => false,
                'puuid' => $data->puuid,
                'summoner_id' => $data->summonerId,

            ]);
        }
        return $summoner;
    }

    public function retryFn($callback, $count = 0)
    {
        $result = $callback();
        if ($result == null && $count < 5) {
            $result = $this->retryFn($callback, $count + 1);
        }
        return $result;
    }


    public function getAndUpdateSummonerByName(string $summonerName): ?Summoner
    {
        $summoner = Summoner::where('name', $summonerName)->first();
        if ($summoner == null || !$summoner->complete) {

            $summonerData = $this->retryFn(fn() => $this->getSummonerByName($summonerName));
            if ($summonerData == null) {
                return null;
            }
            $summoner = $this->updateSummoner($summoner, $summonerData);
        }
        return $summoner;
    }

    public function updateSummonerMatches(Summoner $summoner): Collection
    {
        $matchIds = $this->getAllMatchIds($summoner, null, null);
        $summoner->last_scanned_match = $matchIds->first();
        $summoner->save();
        $matchDbIds = Matche::whereIn('match_id', $matchIds)->pluck('match_id');
        $resultMatchIds = $matchIds->diff($matchDbIds);
        if ($resultMatchIds->isNotEmpty()) {
            Matche::insert($resultMatchIds->map(fn($id) => ['match_id' => $id])->toArray());
        }

        return $resultMatchIds;
    }

    public function getAllMatchIds(Summoner $summoner, $queuId = null, $startDate = null): Collection
    {
        $res = new Collection();
        $offset = 0;
        $limit = 100;
        $max_match_count= config('lol.max_match_count');
        if ($startDate == null) {
            $startDate = Carbon::createFromFormat('d/m/Y', config('lol.min_match_date'))->timestamp;
        }
        $lastScannedMatch = Str::of($summoner->last_scanned_match)->replaceFirst('EUW1_', '',)->toInteger();
        while (true) {

            $data = $this->retryFn(fn() => $this->getMatchIds($summoner, $queuId, $startDate, $limit, $offset));
            if ($data == null) {
                break;
            }
            $res = $res->merge($data);
            if (count($data) < $limit) {
                break;
            }
            $offset += $limit;
        }

        if ($max_match_count && $res->count() > $max_match_count) {
            $res = $res->slice(0, $max_match_count);
        }

        if ($lastScannedMatch) {
            $res = $res->filter(fn($id) => Str::of($id)->replaceFirst('EUW1_', '',)->toInteger() > $lastScannedMatch);
        }
        return $res;
    }


    public function updateMatches(): int
    {
        $matches = Matche::whereUpdated(false)->get();
        $matchDone = 0;
        foreach ($matches as $match) {

            $ok = $this->updateMatch($match);


            if (!$ok) {
                Matche::where('id', $match->id)->delete();
            }
            $matchDone++;
        }

        #$this->clearMatches();
        return $matchDone;
    }



    public function updateMatch(Matche $match): bool
    {
        $data = $this->retryFn(fn() => $this->getMatchDetail($match->match_id));
        $info = $data->info;
        if ($info->gameMode == "" || $info->mapId == 0 || $info->queueId == 0) {
            # custom game
            Log::info("Dosent update match {$match->match_id} because it is a custom game");
            return false;
        }
        $mode = $this->modes->where('name', '=', $info->gameMode)->first();
        SummonerMatch::where('match_id', $match->id)->delete();
        foreach ($info->participants as $participant) {
            $summoner = $this->createOrGetTmpSummoner($participant);
            $championId = Champion::where('id', $participant->championId)->pluck('id')->first();
            if ($championId == null) {
                break;
            }
            $stats = [
                'physical_damage_dealt' => $participant->physicalDamageDealt,
                'physical_damage_dealt_to_champions' => $participant->physicalDamageDealtToChampions,
                'physical_damage_taken' => $participant->physicalDamageTaken,
                'magic_damage_dealt' => $participant->magicDamageDealt,
                'magic_damage_dealt_to_champions' => $participant->magicDamageDealtToChampions,
                'magic_damage_taken' => $participant->magicDamageTaken,
                'true_damage_dealt' => $participant->trueDamageDealt,
                'true_damage_dealt_to_champions' => $participant->trueDamageDealtToChampions,
                'true_damage_taken' => $participant->trueDamageTaken,
                'total_damage_dealt' => $participant->totalDamageDealt,
                'total_damage_dealt_to_champions' => $participant->totalDamageDealtToChampions,
                'total_damage_taken' => $participant->totalDamageTaken,
                'total_heal' => $participant->totalHeal,
                'total_time_cc_dealt' => $participant->totalTimeCCDealt,
                'total_time_spent_dead' => $participant->totalTimeSpentDead,
                'gold_earned' => $participant->goldEarned,
                'gold_spent' => $participant->goldSpent,
            ];
            $summonerMatchParams = [
                'summoner_id' => $summoner->id,
                'match_id' => $match->id,
                'won' => $participant->win,
                'kills' => $participant->kills,
                'deaths' => $participant->deaths,
                'assists' => $participant->assists,
                'champion_id' => $championId,
                'champ_level' => $participant->champLevel,
                'stats' => $stats,
                'minions_killed' => $participant->totalMinionsKilled,
                'largest_killing_spree' => $participant->largestKillingSpree,
                'double_kills' => $participant->doubleKills,
                'triple_kills' => $participant->tripleKills,
                'quadra_kills' => $participant->quadraKills,
                'penta_kills' => $participant->pentaKills,
            ];

            if (isset($participant->challenges)) {
                $summonerMatchParams['challenges'] = $participant->challenges;
            }
            $kda = $participant->kills + $participant->assists;
            if ($participant->deaths > 0) {
                $kda = $kda / $participant->deaths;
            }
            $summonerMatchParams['kda'] = $kda;
            $allKills = $info->teams[($participant->teamId == 100 ? 0 : 1)]->objectives->champion->kills;
            $summonerMatchParams['kill_participation'] = $participant->kills + $participant->assists;
            if ($allKills > 0) {
                $summonerMatchParams['kill_participation'] = round($summonerMatchParams['kill_participation'] / $allKills, 2);
            }
            $sm = SummonerMatch::create($summonerMatchParams);
            $items = [];
            for ($i = 0; $i < 6; $i++) {
                $item = $participant->{'item' . $i};
                $items[] = [
                    'summoner_match_id' => $sm->id,
                    'item_id' => $item,
                    'position' => $i,
                ];
            }
            ItemSummonerMatch::insert($items);
        }
        $match->mode_id = $mode->id;
        $match->map_id = $info->mapId;
        $match->queue_id = $info->queueId;
        $match->match_creation = Carbon::createFromTimestampMs($info->gameStartTimestamp)->format('Y-m-d H:i:s');
        $match->match_duration = Carbon::createFromTimestamp($info->gameDuration)->format('H:i:s');
        $match->updated = true;
        $match->save();
        return true;
    }


    public function clearMatches()
    {
        $ids = collect(DB::select('select t.id
                from (
                    select id,
                        count(*) as cnt
                    from summoner_matches
                    group by summoner_id, match_id
                ) as t
                where cnt > 1'))->map(function ($item) {
            //return $item->id as string;
            return (string)$item->id;
        });

        if ($ids->isNotEmpty()) {
            DB::delete('delete from summoner_matches where id in (' . implode(', ', $ids->toArray()) . ')');
        }
        Log::info('clear matches: ' . $ids->count());
    }


    public function waitApiOk($retry_after): bool
    {
        if (is_array($retry_after)) {
            $retry_after = $retry_after[0];
        }
        if (is_string($retry_after)) {
            $retry_after = intval($retry_after);
        }
        sleep($retry_after+ 5);

        return true;
    }




    public function doGetWithRetry($url, $params = [])
    {
        try {
            $all_params = [
                "headers" => $this->getHeaders(),
                "query" => $params,
            ];
            $response = $this->client->request('GET', $url, $all_params);
            $json = json_decode($response->getBody()->getContents());
            if ($json != null) {
                return $json;
            } else {
                echo 'error ' . PHP_EOL;
                dd('error',);
            }
        } catch (GuzzleException $e) {
            if (str_contains($e->getMessage(), "\"message\":\"Data not found\"")) {

                return null;
            }
            if (array_key_exists('Retry-After', $e->getResponse()->getHeaders())) {
                $retry_after = $e->getResponse()->getHeaders()['Retry-After'];
                $this->waitApiOk($retry_after);
            }
        }
        return null;
    }

    public function getMatchIds(Summoner $summoner, $queueId = null, $startDate = 1641592824, $limit = 100, $offset = 0)
    {
        $url = "https://europe.api.riotgames.com/lol/match/v5/matches/by-puuid/{$summoner->puuid}/ids";
        $params = [
            'startTime' => $startDate,
            'count' => $limit,
            'start' => $offset,
        ];
        if ($queueId) {
            $params['queue'] = $queueId;
        }

        return $this->doGetWithRetry($url, $params);
    }

    public function getSummonerByName(string $summonerName)
    {
        $summonerName = urlencode($summonerName);
        return $this->doGetWithRetry("https://euw1.api.riotgames.com/lol/summoner/v4/summoners/by-name/{$summonerName}");
    }

    public function getMatchDetail($matchId)
    {
        return $this->doGetWithRetry("https://europe.api.riotgames.com/lol/match/v5/matches/$matchId");
    }


    public function getSummonerByPuuid(string $puuid)
    {
        return $this->doGetWithRetry("https://euw1.api.riotgames.com/lol/summoner/v4/summoners/by-puuid/$puuid", []);
    }

    public function getSummonerLiveGame(Summoner $summoner)
    {
        return $this->doGetWithRetry("https://euw1.api.riotgames.com/lol/spectator/v4/active-games/by-summoner/$summoner->summoner_id");
    }

    public function getSummonerLeague(Summoner $summoner)
    {
        return $this->doGetWithRetry("https://euw1.api.riotgames.com/lol/league/v4/entries/by-summoner/$summoner->summoner_id");
    }

    public function getHeaders()
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:91.0) Gecko/20100101 Firefox/91.0',
            'Accept-Language' => 'en-US,en;q=0.5',
            'Accept-Charset' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Origin' => 'https://developer.riotgames.com',
            'X-Riot-Token' => $this->api_key,
        ];
    }


    /**
     * @param mixed $summoner
     * @param mixed $summonerData
     * @return Summoner
     */
    private function updateSummoner(?Summoner $summoner, mixed $summonerData): mixed
    {

        if ($summoner) {
            $summoner->name = $summonerData->name;
            $summoner->profile_icon_id = intval($summonerData->profileIconId);
            $summoner->revision_date = intval($summonerData->revisionDate);
            $summoner->summoner_level = intval($summonerData->summonerLevel);
            $summoner->summoner_id = $summonerData->id;
            $summoner->account_id = $summonerData->accountId;
            $summoner->puuid = $summonerData->puuid;
            $summoner->complete = true;
            $summoner->save();
        } else {
            $summoner = Summoner::firstOrCreate([
                'name' => $summonerData->name,
                'profile_icon_id' => intval($summonerData->profileIconId),
                'revision_date' => intval($summonerData->revisionDate),
                'summoner_level' => intval($summonerData->summonerLevel),
                'summoner_id' => $summonerData->id,
                'account_id' => $summonerData->accountId,
                'puuid' => $summonerData->puuid,
                'complete' => true,
            ]);
        }
        return $summoner;
    }

}
