<div class="h-16">
    <div style="max-width: 60.6167px" class="m-2"
         @click="lol.add_item(item.id)"
{{--    // lolclass get item--}}
         x-tooltip="{

            content : () => lol.get_item_popup(idx),
            allowHTML : true,
            appendTo: $root

         }"

    >


        <img alt=""
             class=" rounded border-b-1 cursor-pointer z-20"
             style="max-width: 50px; max-height: 50px; object-fit: cover; object-position: center"
             :src="'http://ddragon.leagueoflegends.com/cdn/'+lol.version+'/img/item/'+item.id +'.png'"/>
    </div>
</div>
