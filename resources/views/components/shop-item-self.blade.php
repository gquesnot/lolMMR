<div>
    <div style="max-width: 60.6167px" class="m-2"
         @click="lol.remove_item(idx);"
         x-tooltip="{
            content : () => lol.get_item_popup(item.id),
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
