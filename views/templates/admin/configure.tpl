<div class="bootstrap" id="pricesync-dashboard">
    
    <ul class="nav nav-tabs" role="tablist" style="margin-bottom: 20px;">
        <li class="active"><a href="#dashboard" role="tab" data-toggle="tab"><i class="icon-dashboard"></i> DASHBOARD & SYNC</a></li>
        <li><a href="#config" role="tab" data-toggle="tab"><i class="icon-cogs"></i> KONFIGURÁCIÓ</a></li>
        <li><a href="#logs" role="tab" data-toggle="tab"><i class="icon-list"></i> NAPLÓ</a></li>
    </ul>

    <div class="tab-content">
        
        <div class="tab-pane active" id="dashboard">
            {if $psp_mode != 'OFF'}
            <div class="panel" id="bulk-sync-panel">
                <div class="panel-heading"><i class="icon-refresh"></i> Tömeges Szinkronizálás (Bulk Sync)</div>
                <div class="panel-body">
                    <div class="alert alert-info">
                        A rendszer 20-asával halad. Várd meg, amíg a folyamat eléri a 100%-ot!
                    </div>
                    <div class="row">
                        <div class="col-md-4" style="padding-top: 8px;">
                            <strong>Összes aktív termék:</strong> <span id="total_products_count" class="badge">{$total_products}</span> db
                        </div>
                        <div class="col-md-8 text-right">
                            <button type="button" id="btn-start-bulk" class="btn btn-primary" onclick="startBulkSync()">
                                <i class="icon-play"></i> TELJES SZINKRON INDÍTÁSA
                            </button>
                        </div>
                    </div>

                    <div id="sync-progress-wrapper" style="display:none; margin-top: 20px;">
                        <div class="progress">
                            <div class="progress-bar progress-bar-striped active" role="progressbar" id="sync-progress-bar" style="width: 0%">
                                <span id="sync-percentage">0%</span>
                            </div>
                        </div>
                        <div class="text-center text-muted" id="sync-status-text">Indítás...</div>
                        <div class="well" id="bulk-console" style="margin-top:10px; height: 150px; overflow-y: auto; background: #222; color: #0f0; font-family: monospace; font-size: 11px; padding: 5px;">
                            <div>> Rendszer készen áll.</div>
                        </div>
                    </div>
                </div>
            </div>
            {else}
            <div class="alert alert-warning">A modul jelenleg ki van kapcsolva. Állítsd be a Működési Módot a Konfiguráció fülön!</div>
            {/if}
        </div>

        <div class="tab-pane" id="config">
            <div class="panel">
                <div class="panel-heading"><i class="icon-cogs"></i> Beállítások</div>
                <form action="{$action_url}" method="post">
                    <div class="form-group">
                        <label>Működési Mód</label>
                        <select name="PSP_MODE" class="form-control" id="mode_selector">
                            <option value="OFF" {if $psp_mode=='OFF'}selected{/if}>Kikapcsolva</option>
                            <option value="SENDER" {if $psp_mode=='SENDER'}selected{/if}>BESZÁLLÍTÓ (Csak küld)</option>
                            <option value="CHAIN" {if $psp_mode=='CHAIN'}selected{/if}>LÁNC / KÖZTES (Fogad és Továbbküld)</option>
                            <option value="RECEIVER" {if $psp_mode=='RECEIVER'}selected{/if}>VÉGCÉL (Csak fogad)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>API Token (Közös jelszó)</label>
                        <input type="text" name="PSP_TOKEN" value="{$psp_token}" class="form-control">
                    </div>
                    <hr>
                    <div id="sender_settings" style="display:none;">
                        <div class="form-group">
                            <label>Cél URL-ek (Soronként egy)</label>
                            <textarea name="PSP_TARGET_URLS" rows="3" class="form-control">{$psp_target_urls}</textarea>
                        </div>
                    </div>
                    <div id="incoming_settings" style="display:none;">
                        <div class="form-group">
                            <label>Termék Azonosítás</label>
                            <select name="PSP_MATCH_BY" class="form-control">
                                <option value="reference" {if $psp_match_by=='reference'}selected{/if}>Saját Cikkszám</option>
                                <option value="supplier_reference" {if $psp_match_by=='supplier_reference'}selected{/if}>Beszállító Cikkszáma</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Bejövő Szorzó</label>
                            <input type="text" name="PSP_MULTIPLIER" value="{$psp_multiplier}" class="form-control">
                        </div>
                    </div>
                    <div id="chain_settings" style="display:none;">
                        <div class="form-group">
                            <label>Következő Shop URL</label>
                            <input type="text" name="PSP_NEXT_SHOP_URL" value="{$psp_next_shop_url}" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Továbbküldési Szorzó (x85)</label>
                            <input type="text" name="PSP_CHAIN_MULTIPLIER" value="{$psp_chain_multiplier}" class="form-control">
                        </div>
                    </div>
                    <div class="panel-footer">
                        <button type="submit" name="submitPriceSyncConfig" class="btn btn-default pull-right"><i class="process-icon-save"></i> MENTÉS</button>
                    </div>
                </form>
            </div>

            <div class="panel">
                <h3><i class="icon-ban"></i> Tiltólista</h3>
                <form action="{$action_url}" method="post" class="form-inline">
                    <input type="text" name="blacklist_ref" class="form-control" placeholder="Cikkszám...">
                    <button type="submit" name="submitBlacklistAdd" class="btn btn-danger">Tiltás</button>
                </form>
                <table class="table" style="margin-top:10px;">
                    {foreach from=$blacklist item=b}
                    <tr>
                        <td>{if $b.image_url}<img src="{$b.image_url}" width="30">{/if}</td>
                        <td>{$b.product_name} (Ref: {$b.reference})</td>
                        <td class="text-right"><a href="{$action_url}&deleteblacklist=1&id_blacklist={$b.id_blacklist}" class="btn btn-xs btn-default"><i class="icon-trash"></i></a></td>
                    </tr>
                    {/foreach}
                </table>
            </div>
        </div>

        <div class="tab-pane" id="logs">
            <div class="panel">
                <div class="panel-heading">
                    <i class="icon-list"></i> Utolsó 100 esemény
                    <form action="{$action_url}" method="post" style="display:inline; float:right;">
                        <button type="submit" name="clear_logs" class="btn btn-xs btn-default">Napló törlése</button>
                    </form>
                </div>
                <table class="table">
                    <thead><tr><th>Idő</th><th>Típus</th><th>Ref</th><th>Üzenet</th></tr></thead>
                    <tbody>
                        {foreach from=$logs item=log}
                        <tr>
                            <td><small>{$log.date_add}</small></td>
                            <td><span class="label {if $log.type=='success'}label-success{elseif $log.type=='error'}label-danger{else}label-warning{/if}">{$log.type}</span></td>
                            <td><strong>{$log.reference}</strong></td>
                            <td>{$log.message}</td>
                        </tr>
                        {foreachelse}
                        <tr><td colspan="4" class="text-center">Még nincs adat a naplóban.</td></tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    function updateUI() {
        var mode = $('#mode_selector').val();
        $('#sender_settings, #incoming_settings, #chain_settings').hide();
        if (mode == 'SENDER') { $('#sender_settings').show(); }
        else if (mode == 'CHAIN') { $('#incoming_settings, #chain_settings').show(); }
        else if (mode == 'RECEIVER') { $('#incoming_settings').show(); }
    }
    $('#mode_selector').change(updateUI);
    updateUI();

    var totalProducts = {$total_products|intval};
    var ajaxUrl = "{$ajax_url}";
    var processedTotal = 0;

    window.startBulkSync = function() {
        if (!confirm('Indítod?')) return;
        $('#btn-start-bulk').prop('disabled', true);
        $('#sync-progress-wrapper').show();
        processBatch(1);
    };

    function processBatch(page) {
        $.ajax({
            url: ajaxUrl, type: 'POST', data: { page: page }, dataType: 'json',
            success: function(r) {
                if (r.finished) { finish(); } else {
                    processedTotal += r.processed_count;
                    var p = Math.round((processedTotal / totalProducts) * 100);
                    $('#sync-progress-bar').css('width', p + '%');
                    $('#sync-percentage').text(p + '%');
                    $('#bulk-console').prepend('<div>> Batch #' + page + ' kész.</div>');
                    processBatch(r.next_page);
                }
            }
        });
    }
    function finish() {
        $('#sync-progress-bar').removeClass('active').addClass('progress-bar-success');
        $('#btn-start-bulk').prop('disabled', false);
        alert('Kész!');
    }
});
</script>
