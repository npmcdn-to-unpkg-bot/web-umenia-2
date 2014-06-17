@extends('layouts.admin')

@section('title')
@parent
- diela
@stop

@section('content')


<div class="row">
    <div class="col-lg-12">
        <h1 class="page-header">Diela</h1>

        @if (Session::has('message'))
            <div class="alert alert-info alert-dismissable"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>{{ Session::get('message') }}</div>
        @endif

    </div>
    <!-- /.col-lg-12 -->
</div>
<!-- /.row -->

<div class="row">
    <div class="col-lg-12">
        <div class="panel panel-default">
            <div class="panel-heading">
                Diela
            </div>
            <!-- /.panel-heading -->
            <div class="panel-body">
            	<table class="table table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Id</th>
                            <th>Názov</th>
                            <th>Autor</th>
                            <th>Dátum</th>
                            <th>výtvarný druh</th>
                            <th>Akcie</th>
                        </tr>
                    </thead>
                    <tbody>
						@foreach($items as $i)
			            <tr>
                            <td>
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" value="{{ $i->id }}">
                                    </label>
                                </div>
                            </td>
			                <td>{{ $i->id }}</td>
			                <td>{{ $i->title }}</td>
			                <td>{{ $i->author }}</td>
			                <td>{{ $i->dating }}</td>
			                <td>{{ $i->work_type }}</td>
			                <td>{{ link_to_action('ItemController@edit', 'Upraviť', array($i->id), array('class' => 'btn btn-default')) }}</td>
			            </tr>
						@endforeach
                    </tbody>
                </table>

                <div class="text-center"><?php echo $items->links(); ?></div>


            </div>
            <!-- /.panel-body -->
        </div>
        <!-- /.panel -->
    </div>
    <!-- /.col-lg-12 -->
</div>
<!-- /.row -->


@stop