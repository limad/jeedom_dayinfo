
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/
'use strict'

function dayinfoToggleType() {
  const typeEq = document.getElementById('typeEq')
  if (typeEq == null) return
  const type = typeEq.value
  const showGeoloc = (type == 'holidays' || type == 'bankdays' || type == 'global')
  document.getElementById('cmdgeoloc')?.[showGeoloc ? 'seen' : 'unseen']()
  document.getElementById('calendar')?.[type == 'calendar' ? 'seen' : 'unseen']()
}

function printEqLogic(_eqLogic) {
  dayinfoToggleType()
}

document.addEventListener('change', function (event) {
  if (event.target.closest('#typeEq')) {
    dayinfoToggleType()
  }
})

document.getElementById('butCol')?.addEventListener('click', function () {
  const hidCol = document.getElementById('hidCol')
  hidCol.style.display = (hidCol.style.display === 'none') ? '' : 'none'
  document.getElementById('listCol').classList.toggle('col-lg-12')
  document.getElementById('listCol').classList.toggle('col-lg-10')
})

document.addEventListener('click', function (event) {
  const li = event.target.closest('.li_eqLogic')
  if (li == null) return
  event.preventDefault()
  const eqId = li.getAttribute('data-eqlogic_id')
  if (event.ctrlKey || event.metaKey) {
    const type = document.body.getAttribute('data-page')
    window.open('/index.php?v=d&m=' + type + '&p=' + type + '&id=' + eqId).focus()
    return
  }
  li.closest('ul')?.querySelectorAll('li.active').forEach(el => el.classList.remove('active'))
  li.classList.add('active')
  jeeFrontEnd.pluginTemplate.displayEqlogic(li.getAttribute('data-eqlogic_type'), eqId)
})

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  let tr = '<td class="hidden-xs">'
  tr += '<span class="cmdAttr" data-l1key="id"></span>'
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="type" value="' + init(_cmd.type) + '" style="display : none;">'
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="subType" value="' + init(_cmd.subType) + '" style="display : none;">'
  tr += '</td>'

  tr += '<td>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">'
  tr += '<span class="input-group-btn"><a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a></span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'

  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> '
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked/>{{Historiser}}</label> '
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="display" data-l2key="invertBinary"/>{{Inverser}}</label> '
  tr += '<div style="margin-top:7px;">'
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min}}" title="{{Min}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">'
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max}}" title="{{Max}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">'
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="unite" placeholder="Unité" title="{{Unité}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">'
  tr += '</div>'
  tr += '</td>'

  tr += '<td>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate"></span>'
  tr += '</td>'

  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>'
  }
  tr += '</td>'

  const newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.addClass('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)

  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

document.getElementById('bt_createByType')?.addEventListener('click', function () {
  jeeDialog.confirm({
    title: '{{Créer un équipement par type ?}}',
    message: '{{Un équipement sera créé pour chaque type manquant : Jours fériés, Vacances scolaires, Lune, Divers, Global et Calendrier iCal (emplacement du calendrier à renseigner ensuite). Les types déjà existants sont conservés.}}'
  }, function (confirmed) {
    if (!confirmed) return
    domUtils.ajax({
      type: 'POST',
      url: 'plugins/dayinfo/core/ajax/dayinfo.ajax.php',
      data: { action: 'createByType' },
      dataType: 'json',
      error: function (request, status, error) {
        jeedomUtils.showAlert({ message: error, level: 'danger' })
      },
      success: function (data) {
        if (data.state != 'ok') {
          jeedomUtils.showAlert({ message: data.result, level: 'danger' })
          return
        }
        const msg = data.result.length > 0
          ? '{{Équipements créés}} : ' + data.result.join(', ')
          : '{{Aucun équipement à créer : tous les types existent déjà.}}'
        jeedomUtils.showAlert({ message: msg, level: 'success' })
        if (data.result.length > 0) {
          setTimeout(function () { window.location.reload() }, 1500)
        }
      }
    })
  })
})
