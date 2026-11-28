{{-- One result of the chain (an outcome of the programme or an output of the subprogramme), as the State Budget
     prints it: its code and statement, its indicator, starting point and target, and the subtotal of the activities
     that serve it. --}}
<tr class="{{ $class }}">
    <td>{{ $kind }}@if($result->code) <b>{{ $result->code }}</b>@endif: {{ $result->statement ?: $result->name }}</td>
    <td>{{ $result->statement ? $result->name : '' }}</td>
    <td>{{ $result->baseline }}</td>
    <td>{{ $result->target }}</td>
    <td>{{ $result->means_of_verification }}</td>
    <td colspan="6"></td>
    @foreach($classes as $c)<td class="r">{{ $num($sums['by_class'][$c->id] ?? 0) }}</td>@endforeach
    <td class="r">{{ $num($sums['total']) }}</td>
</tr>
