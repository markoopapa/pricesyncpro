<div class="panel">
    <h3><i class="icon-ban"></i> Tiltólista (Blacklist)</h3>
    
    <form action="{$form_action}" method="post" class="form-inline" style="margin-bottom: 20px;">
        <div class="form-group">
            <label for="blacklist_reference">Cikkszám (Reference): </label>
            <input type="text" class="form-control" name="blacklist_reference" id="blacklist_reference" placeholder="Pl. REF-1234" required>
        </div>
        <button type="submit" name="submitAddBlacklist" class="btn btn-danger">
            <i class="icon-plus"></i> Hozzáadás a tiltólistához
        </button>
    </form>

    <hr>

    <table class="table">
        <thead>
            <tr>
                <th>Kép</th>
                <th>Cikkszám (Ref)</th>
                <th>Termék Név</th>
                <th>Hozzáadva</th>
                <th>Művelet</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$blacklist_items item=item}
            <tr>
                <td>
                    {if $item.image_url}
                        <img src="{$item.image_url}" style="width: 50px; height: 50px; object-fit: cover; border: 1px solid #ddd;">
                    {else}
                        <span class="text-muted">Nincs kép</span>
                    {/if}
                </td>
                <td><strong>{$item.reference}</strong></td>
                <td>{$item.product_name}</td>
                <td>{$item.date_add}</td>
                <td>
                    <form action="{$form_action}" method="post">
                        <input type="hidden" name="id_blacklist" value="{$item.id_blacklist}">
                        <button type="submit" name="deleteblacklist" class="btn btn-default btn-xs" onclick="return confirm('Biztosan törlöd a tiltólistáról?');">
                            <i class="icon-trash"></i> Törlés
                        </button>
                    </form>
                </td>
            </tr>
            {foreachelse}
            <tr>
                <td colspan="5" class="text-center">A tiltólista jelenleg üres.</td>
            </tr>
            {/foreach}
        </tbody>
    </table>
</div>
