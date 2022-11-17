<div @class(["bg-blue-200" => $match->won , "bg-red-200"=> !$match->won, "flex my-2 p-2"])>
	<div class="flex relative mx-2 items-center w-1/6">
		<div class="flex flex-col text-center w-2/3">
			<div class="font-medium">{{$match->match->mode->name}}</div>
			<div>{{$match->match->since_match_end}}</div>
			<div @class(["text-blue-600" => $match->won , "text-red-600"=>!$match->won, "text-center"])>{{$match->won ? "won" : "lose"}}</div>
			<div>{{$match->match->match_duration}}</div>

		</div>
		{{--                                champion img url --}}
		<div class="flex flex-col text-center w-1/3">
			<div class="relative flex justify-center items-center ml-4">
				<img
					src="http://ddragon.leagueoflegends.com/cdn/{{$version}}/img/champion/{{$match->champion->img_url}}"
					alt="{{$match->champion->name}}"
					class="w-16 h-16 rounded-full">
			</div>
			<div>{{$match->champion->name}}</div>
		</div>
	</div>
	<div
		class=" flex flex-col items-center font-medium flex justify-center items-center mx-2 w-1/12">
		<div class="font-bold flex items-center">
			<span class="mx-2">{{$match->kills}}</span>/<span
				class="mx-2 text-red-600">{{$match->deaths}}</span>/<span
				class="mx-2">{{$match->assists}}</span>
		</div>
		<div>
			{{$match->kda}} KDA
		</div>
	</div>
	<div class="flex flex-col items-center justify-center text-center mx-2 w-1/12">
		<div>Level {{$match->champ_level}}</div>
		<div>{{$match->minions_killed}} ({{$match->csPerMinute}}) CS</div>
		<div class="text-red-600">P/Kill {{$match->kill_participation * 100}}%</div>
	</div>
	<div class="flex w-36 flex-wrap  items-start mx-2 py-6 ">
		@foreach($match->items as $item)
			<div class="w-1/3">
				<div class="relative ml-4">
					<img alt="{{$item->name}}"
					     src="http://ddragon.leagueoflegends.com/cdn/{{$version}}/img/item/{{$item->img_url}}"
					     class="w-8 h-8 rounded">
				</div>
			</div>
		@endforeach
	</div>
	<div class="flex mx-4 min-w-fit">
		<div class="flex flex-col w-1/2 pr-10 mr-10">
			@foreach($match->match->participants as $participant)
				@if($match->won == $participant->won)
					<x-match-participant :participant="$participant" :me="$me" :version="$version" :match="$match" :won="$participant->won"/>
				@endif

			@endforeach
		</div>
		<div class="flex flex-col w-1/2">
			@foreach($match->match->participants as $participant)
				@if($match->won != $participant->won)
					<x-match-participant :participant="$participant" :me="$me" :version="$version" :match="$match" :won="$participant->won"/>
				@endif
			@endforeach
		</div>
	</div>
</div>