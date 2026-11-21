<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>
@foreach($shapes as $id => $s)
<p:sp><p:nvSpPr><p:cNvPr id="{{ $id+2 }}" name="{{ $s['text'] ? __('Текст') : __('Фигура') }} {{ $id+1 }}"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr>
<a:xfrm><a:off x="{{ round($s['x']*914400) }}" y="{{ round($s['y']*914400) }}"/><a:ext cx="{{ round($s['w']*914400) }}" cy="{{ round($s['h']*914400) }}"/></a:xfrm>
<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
@if($s['fill'])<a:solidFill><a:srgbClr val="{{ $s['fill'] }}"/></a:solidFill>@else<a:noFill/>@endif
<a:ln><a:noFill/></a:ln></p:spPr>
@if($s['text'] !== '')
<p:txBody><a:bodyPr lIns="40000" rIns="40000" tIns="0" bIns="0" anchor="ctr"><a:normAutofit/></a:bodyPr><a:lstStyle/><a:p><a:r><a:rPr lang="ru-RU" sz="{{ $s['size']*100 }}" b="{{ (int)$s['bold'] }}"><a:solidFill><a:srgbClr val="{{ $s['color'] }}"/></a:solidFill><a:latin typeface="Arial"/>
@if($s['url'])<a:hlinkClick r:id="link{{ $id }}"/>@endif
</a:rPr><a:t>{{ $s['text'] }}</a:t></a:r></a:p></p:txBody>
@endif
</p:sp>
@endforeach
</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>
